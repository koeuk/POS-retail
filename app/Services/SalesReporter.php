<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read models for the dashboard and reports.
 *
 * Two rules run through everything here:
 *
 *  1. **Bucket by business day, not row age.** An offline batch can reach the
 *     server days after the sale. Grouping on created_at would credit Tuesday's
 *     takings to Thursday and make every daily figure wrong.
 *  2. **Only completed orders count.** Refunded and void orders stay in the
 *     table for audit, and must never inflate a sales total.
 */
class SalesReporter
{
    public function __construct(private readonly ?int $storeId = null) {}

    /**
     * COALESCE(created_offline_at, created_at) — when the sale actually
     * happened.
     *
     * Returned as raw SQL text, not a DB::raw() Expression: Laravel 11 dropped
     * Expression::__toString(), so an Expression cannot be concatenated into a
     * whereRaw() clause the way these call sites need.
     */
    public static function businessDay(): string
    {
        return 'DATE(COALESCE(orders.created_offline_at, orders.created_at))';
    }

    public static function businessMoment(): string
    {
        return 'COALESCE(orders.created_offline_at, orders.created_at)';
    }

    /** Admins see every store; everyone else is pinned to their own. */
    public static function for(User $user): self
    {
        return new self($user->isAdmin() ? null : $user->store_id);
    }

    private function orders(): Builder
    {
        return Order::query()
            ->where('status', OrderStatus::Completed)
            ->when($this->storeId, fn ($q, $id) => $q->where('store_id', $id));
    }

    /* ------------------------------------------------------------------ */
    /* Dashboard */
    /* ------------------------------------------------------------------ */

    /** @return array{sales: string, orders: int, basket: string, items: int} */
    public function summaryFor(Carbon $day): array
    {
        $row = $this->orders()
            ->whereRaw(self::businessDay().' = ?', [$day->toDateString()])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total), 0) as sales')
            ->first();

        $orders = (int) ($row->order_count ?? 0);
        $sales = (float) ($row->sales ?? 0);

        $items = (int) OrderItem::whereIn('order_id', $this->orders()
            ->whereRaw(self::businessDay().' = ?', [$day->toDateString()])
            ->select('id'))
            ->sum('qty');

        return [
            'sales' => number_format($sales, 2, '.', ''),
            'orders' => $orders,
            'basket' => number_format($orders > 0 ? $sales / $orders : 0, 2, '.', ''),
            'items' => $items,
        ];
    }

    /** Products at or below their threshold — what to reorder. */
    public function lowStock(int $limit = 8): Collection
    {
        return Stock::query()
            ->with(['product:id,name,unit', 'store:id,name'])
            ->whereNotNull('low_stock_threshold')
            ->whereColumn('qty', '<=', 'low_stock_threshold')
            ->where('qty', '>=', 0)
            ->when($this->storeId, fn ($q, $id) => $q->where('store_id', $id))
            ->orderBy('qty')
            ->limit($limit)
            ->get();
    }

    /**
     * Stock driven below zero by offline sales that synced after the shelf
     * was already empty. This is the reconciliation list — the deliberate
     * cost of never rejecting a completed sale.
     */
    public function oversold(int $limit = 8): Collection
    {
        return Stock::query()
            ->with(['product:id,name,unit', 'store:id,name'])
            ->where('qty', '<', 0)
            ->when($this->storeId, fn ($q, $id) => $q->where('store_id', $id))
            ->orderBy('qty')
            ->limit($limit)
            ->get();
    }

    public function recentOrders(int $limit = 8): Collection
    {
        return $this->orders()
            ->with(['cashier:id,name', 'store:id,name'])
            ->orderByRaw(self::businessMoment().' DESC')
            ->limit($limit)
            ->get(['id', 'order_no', 'total', 'created_at', 'created_offline_at', 'cashier_id', 'store_id']);
    }

    /** Sales queued offline and not yet reconciled anywhere. */
    public function offlineOrdersToday(Carbon $day): int
    {
        return $this->orders()
            ->whereNotNull('created_offline_at')
            ->whereRaw(self::businessDay().' = ?', [$day->toDateString()])
            ->count();
    }

    /* ------------------------------------------------------------------ */
    /* Reports */
    /* ------------------------------------------------------------------ */

    /** @return Collection<int, object{day: string, orders: int, sales: string}> */
    public function salesByDay(Carbon $from, Carbon $to): Collection
    {
        $rows = $this->orders()
            ->whereRaw(self::businessDay().' BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->selectRaw(self::businessDay().' as day, COUNT(*) as orders, SUM(total) as sales')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        // Fill the gaps so a quiet day reads as zero rather than vanishing and
        // making the chart lie about its own time axis.
        $out = collect();

        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);

            $out->push([
                'day' => $key,
                'orders' => (int) ($row->orders ?? 0),
                'sales' => number_format((float) ($row->sales ?? 0), 2, '.', ''),
            ]);
        }

        return $out;
    }

    public function salesByProduct(Carbon $from, Carbon $to, int $limit = 20): Collection
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', OrderStatus::Completed)
            ->when($this->storeId, fn ($q, $id) => $q->where('orders.store_id', $id))
            ->whereRaw(self::businessDay().' BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            // Group on the snapshot name: what the customer was actually sold,
            // even if the product has been renamed since.
            ->selectRaw('order_items.product_name, SUM(order_items.qty) as qty, SUM(order_items.subtotal) as revenue')
            ->groupBy('order_items.product_name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_name' => $row->product_name,
                'qty' => (int) $row->qty,
                'revenue' => number_format((float) $row->revenue, 2, '.', ''),
            ]);
    }

    public function paymentBreakdown(Carbon $from, Carbon $to): Collection
    {
        return Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.status', OrderStatus::Completed)
            ->when($this->storeId, fn ($q, $id) => $q->where('orders.store_id', $id))
            ->whereRaw(self::businessDay().' BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('payments.method, COUNT(*) as count, SUM(payments.amount) as amount')
            ->groupBy('payments.method')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->method,
                'count' => (int) $row->count,
                'amount' => number_format((float) $row->amount, 2, '.', ''),
            ]);
    }

    /** @return array{orders: int, sales: string, items: int, basket: string} */
    public function rangeTotals(Carbon $from, Carbon $to): array
    {
        $row = $this->orders()
            ->whereRaw(self::businessDay().' BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total), 0) as sales')
            ->first();

        $orders = (int) ($row->order_count ?? 0);
        $sales = (float) ($row->sales ?? 0);

        $items = (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', OrderStatus::Completed)
            ->when($this->storeId, fn ($q, $id) => $q->where('orders.store_id', $id))
            ->whereRaw(self::businessDay().' BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->sum('order_items.qty');

        return [
            'orders' => $orders,
            'sales' => number_format($sales, 2, '.', ''),
            'items' => $items,
            'basket' => number_format($orders > 0 ? $sales / $orders : 0, 2, '.', ''),
        ];
    }
}
