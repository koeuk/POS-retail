<?php

namespace App\Services;

use App\Enums\InventoryLogType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns queued offline sales into real orders.
 *
 * The contract this class has to honour, in order of importance:
 *
 *  1. **Retrying is free.** The same client_uuid may arrive any number of
 *     times, from any number of tabs, and must produce exactly one order with
 *     exactly one stock movement.
 *  2. **A completed sale is never rejected.** The cash is already in the
 *     drawer and the goods have left the shop. Insufficient stock is recorded,
 *     not refused — stocks.qty is signed for exactly this reason.
 *  3. **Stock only moves here.** Client-side stock maths is a display hint;
 *     this is the ledger.
 *  4. **One bad order does not sink the batch.** Every order is reported
 *     individually so a single malformed row cannot strand the other 49.
 */
class OrderSyncService
{
    /** Guards against an order_no collision looping forever. */
    private const MAX_ATTEMPTS = 5;

    /**
     * @param  array<int, array<string, mixed>>  $orders
     * @return array<int, array<string, mixed>> one result per order, keyed by client_uuid
     */
    public function syncMany(array $orders, User $cashier): array
    {
        return array_map(fn (array $order) => $this->syncOne($order, $cashier), $orders);
    }

    /** @param array<string, mixed> $payload */
    public function syncOne(array $payload, User $cashier): array
    {
        $uuid = $payload['client_uuid'];

        // Fast path: already landed on a previous flush.
        if ($existing = Order::where('client_uuid', $uuid)->first()) {
            return $this->result($uuid, 'already_synced', $existing);
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $order = DB::transaction(fn () => $this->createOrder($payload, $cashier));

                return $this->result($uuid, 'created', $order);
            } catch (UniqueConstraintViolationException $e) {
                // Either another flush won the race on client_uuid, or two
                // registers grabbed the same order_no. Only the first is done.
                if ($winner = Order::where('client_uuid', $uuid)->first()) {
                    return $this->result($uuid, 'already_synced', $winner);
                }

                // order_no collision — take the next sequence number and retry.
                continue;
            } catch (Throwable $e) {
                Log::error('POS order sync failed', [
                    'client_uuid' => $uuid,
                    'cashier_id' => $cashier->id,
                    'message' => $e->getMessage(),
                ]);

                return $this->result($uuid, 'failed', null, $e->getMessage());
            }
        }

        return $this->result($uuid, 'failed', null, 'Could not allocate an order number.');
    }

    /** @param array<string, mixed> $payload */
    private function createOrder(array $payload, User $cashier): Order
    {
        $storeId = (int) ($payload['store_id'] ?? $cashier->store_id);
        $items = $payload['items'];

        $totals = new OrderTotals($items, $payload['discount_amount'] ?? 0);

        $offlineAt = isset($payload['created_offline_at'])
            ? Carbon::parse($payload['created_offline_at'])
            : null;

        $paid = array_sum(array_map(
            fn ($p) => OrderTotals::toCents($p['amount']),
            $payload['payments'] ?? []
        ));

        // Change is only ever given against cash; card and QR are exact.
        $givesChange = collect($payload['payments'] ?? [])
            ->contains(fn ($p) => $p['method'] === 'cash');

        $change = $givesChange
            ? max(0, $paid - OrderTotals::toCents($totals->total()))
            : 0;

        $order = Order::create([
            'client_uuid' => $payload['client_uuid'],
            'order_no' => $this->nextOrderNo($storeId, $payload['register_id'] ?? null, $offlineAt),
            'store_id' => $storeId,
            'register_id' => $payload['register_id'] ?? null,
            'cashier_id' => $cashier->id,
            'customer_id' => $payload['customer_id'] ?? null,
            'subtotal' => $totals->subtotal(),
            'discount_amount' => $totals->discountAmount(),
            'tax_amount' => $totals->taxAmount(),
            'total' => $totals->total(),
            'paid_amount' => OrderTotals::toDecimal($paid),
            'change_amount' => OrderTotals::toDecimal($change),
            'status' => OrderStatus::Completed,
            'synced_at' => now(),
            'created_offline_at' => $offlineAt,
        ]);

        $products = Product::whereIn('id', array_column($items, 'product_id'))
            ->get()
            ->keyBy('id');

        foreach ($items as $index => $item) {
            $product = $products->get($item['product_id']);

            $order->items()->create([
                'product_id' => $item['product_id'],
                'product_variant_id' => null,

                // Snapshots. The name and price are whatever the customer was
                // shown at the till, not whatever the product says today.
                'product_name' => $item['product_name'] ?? $product?->name ?? 'Unknown product',
                'unit_price' => OrderTotals::toDecimal(OrderTotals::toCents($item['unit_price'])),
                'qty' => (int) $item['qty'],
                'discount' => OrderTotals::toDecimal(OrderTotals::toCents($item['discount'] ?? 0)),
                'subtotal' => $totals->lineSubtotal($index),
            ]);

            if ($product?->track_stock) {
                // A pack sells in base units: one case of 24 takes 24 off the
                // can's shelf, not one off a shelf of cases.
                $this->moveStock($product, $storeId, $product->baseUnits((int) $item['qty']), $order, $cashier);
            }
        }

        foreach ($payload['payments'] ?? [] as $payment) {
            $order->payments()->create([
                'method' => $payment['method'],
                'amount' => OrderTotals::toDecimal(OrderTotals::toCents($payment['amount'])),
                'reference_no' => $payment['reference_no'] ?? null,
            ]);
        }

        return $order;
    }

    /**
     * Decrement stock and record why. `$qty` is in **base units**, already
     * multiplied out by the caller. The decrement is a single SQL statement so
     * two concurrent syncs cannot read-modify-write over each other, and qty is
     * allowed to go negative — see rule 2 in the class docblock.
     */
    private function moveStock(Product $product, int $storeId, int $qty, Order $order, User $cashier): void
    {
        // Stock hangs off the base product; a pack has no shelf of its own.
        $stockProductId = $product->stockProductId();

        $stock = Stock::firstOrCreate(
            ['product_id' => $stockProductId, 'store_id' => $storeId],
            ['qty' => 0],
        );

        $stock->decrement('qty', $qty);

        $order->store->inventoryLogs()->create([
            'product_id' => $stockProductId,
            'type' => InventoryLogType::Sale,
            'qty_change' => -$qty,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'note' => null,
            'created_by' => $cashier->id,
        ]);
    }

    /**
     * Format: S{store}-R{register}-{YYMMDD}-{seq}
     *
     * Sequence is per store per business day, and the business day is when the
     * sale actually happened — not when it reached the server. An offline batch
     * from yesterday must number itself into yesterday's run.
     */
    private function nextOrderNo(int $storeId, ?int $registerId, ?Carbon $offlineAt): string
    {
        $day = ($offlineAt ?? now())->copy();

        $countedToday = Order::where('store_id', $storeId)
            ->where(function ($query) use ($day) {
                $query->whereDate('created_offline_at', $day->toDateString())
                    ->orWhere(function ($q) use ($day) {
                        $q->whereNull('created_offline_at')
                            ->whereDate('created_at', $day->toDateString());
                    });
            })
            ->count();

        return sprintf(
            'S%d-R%d-%s-%04d',
            $storeId,
            $registerId ?? 0,
            $day->format('ymd'),
            $countedToday + 1,
        );
    }

    private function result(string $uuid, string $status, ?Order $order, ?string $message = null): array
    {
        return [
            'client_uuid' => $uuid,
            'status' => $status,
            'order_id' => $order?->id,
            'order_no' => $order?->order_no,
            'total' => $order?->total,
            'message' => $message,
        ];
    }
}
