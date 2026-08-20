<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Register;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PosSyncTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Register $register;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->register = Register::factory()->create(['store_id' => $this->store->id]);
        $this->cashier = User::factory()->cashier($this->store)->create();
    }

    private function stockedProduct(int $qty = 100, array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes);

        Stock::create([
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'qty' => $qty,
            'low_stock_threshold' => 5,
        ]);

        return $product;
    }

    /** @param array<int, array<string, mixed>> $items */
    private function orderPayload(array $items, array $overrides = []): array
    {
        $payments = $overrides['payments'] ?? [
            ['method' => 'cash', 'amount' => '1000.00', 'reference_no' => null],
        ];

        return array_merge([
            'client_uuid' => (string) Str::uuid(),
            'register_id' => $this->register->id,
            'customer_id' => null,
            'created_offline_at' => now()->toIso8601String(),
            'discount_amount' => '0.00',
            'items' => $items,
            'payments' => $payments,
        ], $overrides);
    }

    private function line(Product $product, int $qty = 1, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'qty' => $qty,
            'unit_price' => $product->sell_price,
            'discount' => '0.00',
            'tax_rate' => $product->tax_rate ?? 0,
        ], $overrides);
    }

    private function sync(array $orders): TestResponse
    {
        return $this->actingAs($this->cashier)
            ->postJson(route('pos.data.orders.sync'), ['orders' => $orders]);
    }

    /* ================================================================== */
    /* Idempotency — the reason this endpoint exists */
    /* ================================================================== */

    public function test_posting_the_same_client_uuid_twice_creates_exactly_one_order(): void
    {
        $product = $this->stockedProduct(100);
        $payload = $this->orderPayload([$this->line($product, 3)]);

        $this->sync([$payload])->assertOk()
            ->assertJsonPath('results.0.status', 'created');

        $this->sync([$payload])->assertOk()
            ->assertJsonPath('results.0.status', 'already_synced');

        $this->assertSame(1, Order::where('client_uuid', $payload['client_uuid'])->count());
    }

    public function test_a_replayed_order_does_not_decrement_stock_a_second_time(): void
    {
        $product = $this->stockedProduct(100);
        $payload = $this->orderPayload([$this->line($product, 4)]);

        $this->sync([$payload])->assertOk();
        $this->sync([$payload])->assertOk();
        $this->sync([$payload])->assertOk();

        $this->assertSame(96, $this->stockQty($product));
        $this->assertSame(1, $product->inventoryLogs()->count());
    }

    /**
     * Two tabs can flush the same queue at the same moment. The unique index
     * on client_uuid is what stops that becoming two orders; this proves the
     * violation is caught and reported rather than thrown at the cashier.
     */
    public function test_a_racing_duplicate_is_reported_as_already_synced(): void
    {
        $product = $this->stockedProduct(50);
        $uuid = (string) Str::uuid();

        $payload = $this->orderPayload([$this->line($product, 2)], ['client_uuid' => $uuid]);

        // Simulate the loser of the race: the row already exists when the
        // second request reaches the insert.
        $this->sync([$payload])->assertOk();

        $second = $this->sync([$payload])->assertOk();

        $second->assertJsonPath('results.0.status', 'already_synced');
        $this->assertSame(1, Order::where('client_uuid', $uuid)->count());
        $this->assertSame(48, $this->stockQty($product));
    }

    public function test_a_batch_of_ten_offline_orders_syncs_in_one_call(): void
    {
        $product = $this->stockedProduct(100);

        $orders = collect(range(1, 10))
            ->map(fn () => $this->orderPayload([$this->line($product, 2)]))
            ->all();

        $response = $this->sync($orders)->assertOk();

        $this->assertCount(10, $response->json('results'));
        $this->assertSame(10, Order::count());
        $this->assertSame(80, $this->stockQty($product));

        // Every order gets its own number.
        $this->assertSame(10, Order::distinct()->count('order_no'));
    }

    public function test_one_bad_order_does_not_sink_the_rest_of_the_batch(): void
    {
        $product = $this->stockedProduct(100);

        $good = $this->orderPayload([$this->line($product, 1)]);
        $bad = $this->orderPayload([$this->line($product, 1, ['product_id' => 999_999])]);

        // A non-existent product is caught by validation before anything runs,
        // so the whole batch is rejected — the client must not silently drop it.
        $this->sync([$good, $bad])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    /* ================================================================== */
    /* Stock */
    /* ================================================================== */

    public function test_stock_decrements_by_the_right_amount_exactly_once(): void
    {
        $a = $this->stockedProduct(20);
        $b = $this->stockedProduct(7);

        $this->sync([
            $this->orderPayload([$this->line($a, 3), $this->line($b, 2)]),
        ])->assertOk();

        $this->assertSame(17, $this->stockQty($a));
        $this->assertSame(5, $this->stockQty($b));
    }

    public function test_an_inventory_log_is_written_for_every_line_item(): void
    {
        $a = $this->stockedProduct(20);
        $b = $this->stockedProduct(20);

        $this->sync([
            $this->orderPayload([$this->line($a, 3), $this->line($b, 5)]),
        ])->assertOk();

        $order = Order::firstOrFail();

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $a->id,
            'store_id' => $this->store->id,
            'type' => 'sale',
            'qty_change' => -3,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'created_by' => $this->cashier->id,
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $b->id,
            'qty_change' => -5,
        ]);
    }

    /**
     * The cash is in the drawer and the goods have gone. Refusing the sale
     * would strand it in the queue forever, so stock is allowed to go negative
     * and the discrepancy is surfaced on the dashboard instead.
     */
    public function test_an_offline_sale_may_drive_stock_negative_and_is_never_rejected(): void
    {
        $product = $this->stockedProduct(2);

        $this->sync([$this->orderPayload([$this->line($product, 5)])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'created');

        $this->assertSame(-3, $this->stockQty($product));
        $this->assertDatabaseHas('inventory_logs', ['product_id' => $product->id, 'qty_change' => -5]);
    }

    public function test_a_product_that_does_not_track_stock_moves_no_stock(): void
    {
        $product = $this->stockedProduct(10, ['track_stock' => false]);

        $this->sync([$this->orderPayload([$this->line($product, 4)])])->assertOk();

        $this->assertSame(10, $this->stockQty($product));
        $this->assertSame(0, $product->inventoryLogs()->count());
    }

    /* ================================================================== */
    /* Money */
    /* ================================================================== */

    public function test_tax_is_charged_per_line_on_top_of_the_price(): void
    {
        $taxed = $this->stockedProduct(10, ['sell_price' => '10.00', 'tax_rate' => '10.00']);
        $free = $this->stockedProduct(10, ['sell_price' => '5.00', 'tax_rate' => null]);

        $this->sync([
            $this->orderPayload([
                $this->line($taxed, 2, ['tax_rate' => '10.00']),
                $this->line($free, 1, ['tax_rate' => 0]),
            ]),
        ])->assertOk();

        $order = Order::firstOrFail();

        $this->assertSame('25.00', $order->subtotal);   // 20.00 + 5.00
        $this->assertSame('2.00', $order->tax_amount);  // 10% of 20.00 only
        $this->assertSame('27.00', $order->total);
    }

    /**
     * With mixed tax rates, an order discount has to be spread across the
     * lines before tax — applying it to the total instead would charge the
     * wrong tax on each band.
     */
    public function test_an_order_discount_is_spread_across_lines_before_tax(): void
    {
        $taxed = $this->stockedProduct(10, ['sell_price' => '10.00', 'tax_rate' => '10.00']);
        $free = $this->stockedProduct(10, ['sell_price' => '10.00', 'tax_rate' => null]);

        $this->sync([
            $this->orderPayload([
                $this->line($taxed, 1, ['tax_rate' => '10.00']),
                $this->line($free, 1, ['tax_rate' => 0]),
            ], ['discount_amount' => '4.00']),
        ])->assertOk();

        $order = Order::firstOrFail();

        // 20.00 subtotal, 4.00 off split evenly => each line taxable 8.00.
        // Only the first line is taxable: 10% of 8.00 = 0.80.
        $this->assertSame('20.00', $order->subtotal);
        $this->assertSame('4.00', $order->discount_amount);
        $this->assertSame('0.80', $order->tax_amount);
        $this->assertSame('16.80', $order->total);
    }

    public function test_a_line_discount_comes_off_before_the_line_is_taxed(): void
    {
        $product = $this->stockedProduct(10, ['sell_price' => '10.00', 'tax_rate' => '10.00']);

        $this->sync([
            $this->orderPayload([
                $this->line($product, 2, ['discount' => '5.00', 'tax_rate' => '10.00']),
            ]),
        ])->assertOk();

        $order = Order::firstOrFail();

        $this->assertSame('15.00', $order->subtotal);   // 20.00 - 5.00
        $this->assertSame('1.50', $order->tax_amount);
        $this->assertSame('16.50', $order->total);
        $this->assertSame('15.00', $order->items()->first()->subtotal);
    }

    public function test_change_is_given_on_cash_but_not_on_card(): void
    {
        $product = $this->stockedProduct(10, ['sell_price' => '10.00', 'tax_rate' => null]);

        $cash = $this->orderPayload([$this->line($product, 1, ['tax_rate' => 0])], [
            'payments' => [['method' => 'cash', 'amount' => '20.00', 'reference_no' => null]],
        ]);

        $card = $this->orderPayload([$this->line($product, 1, ['tax_rate' => 0])], [
            'payments' => [['method' => 'card', 'amount' => '10.00', 'reference_no' => 'TXN-1']],
        ]);

        $this->sync([$cash, $card])->assertOk();

        $cashOrder = Order::where('client_uuid', $cash['client_uuid'])->firstOrFail();
        $cardOrder = Order::where('client_uuid', $card['client_uuid'])->firstOrFail();

        $this->assertSame('20.00', $cashOrder->paid_amount);
        $this->assertSame('10.00', $cashOrder->change_amount);

        $this->assertSame('10.00', $cardOrder->paid_amount);
        $this->assertSame('0.00', $cardOrder->change_amount);
    }

    /**
     * The whole reason order_items snapshots name and price: a sale synced a
     * week late must still show what the customer was actually charged.
     */
    public function test_line_items_keep_the_price_from_the_time_of_sale(): void
    {
        $product = $this->stockedProduct(10, ['name' => 'Old Name', 'sell_price' => '2.00']);

        $payload = $this->orderPayload([
            $this->line($product, 1, ['product_name' => 'Old Name', 'unit_price' => '2.00']),
        ]);

        $product->update(['name' => 'New Name', 'sell_price' => '9.99']);

        $this->sync([$payload])->assertOk();

        $item = Order::firstOrFail()->items()->firstOrFail();

        $this->assertSame('Old Name', $item->product_name);
        $this->assertSame('2.00', $item->unit_price);
    }

    /* ================================================================== */
    /* Order numbering, payments, endpoints */
    /* ================================================================== */

    public function test_order_numbers_are_sequential_per_store_per_business_day(): void
    {
        $product = $this->stockedProduct(100);
        $day = now()->subDays(2);

        $orders = collect(range(1, 3))
            ->map(fn () => $this->orderPayload(
                [$this->line($product, 1)],
                ['created_offline_at' => $day->toIso8601String()],
            ))
            ->all();

        $this->sync($orders)->assertOk();

        $numbers = Order::orderBy('id')->pluck('order_no')->all();
        $stamp = $day->format('ymd');

        $this->assertSame([
            "S{$this->store->id}-R{$this->register->id}-{$stamp}-0001",
            "S{$this->store->id}-R{$this->register->id}-{$stamp}-0002",
            "S{$this->store->id}-R{$this->register->id}-{$stamp}-0003",
        ], $numbers);
    }

    public function test_payments_are_recorded_against_the_order(): void
    {
        $product = $this->stockedProduct(10, ['sell_price' => '10.00', 'tax_rate' => null]);

        $this->sync([
            $this->orderPayload([$this->line($product, 1, ['tax_rate' => 0])], [
                'payments' => [['method' => 'qr', 'amount' => '10.00', 'reference_no' => 'QR-9']],
            ]),
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'order_id' => Order::firstOrFail()->id,
            'method' => 'qr',
            'amount' => '10.00',
            'reference_no' => 'QR-9',
        ]);
    }

    public function test_the_product_feed_carries_everything_the_pos_needs_offline(): void
    {
        $this->stockedProduct(42, ['name' => 'Cached Cola']);
        Product::factory()->inactive()->create(['name' => 'Hidden']);

        $response = $this->actingAs($this->cashier)
            ->getJson(route('pos.data.products'))
            ->assertOk()
            ->assertJsonStructure([
                'store_id', 'synced_at', 'categories', 'registers',
                'settings' => ['receipt_header', 'receipt_footer', 'currency_symbol'],
                'products' => [['id', 'name', 'sku', 'barcode', 'sell_price', 'tax_rate', 'stock_qty']],
            ]);

        $names = collect($response->json('products'))->pluck('name');

        $this->assertTrue($names->contains('Cached Cola'));
        $this->assertFalse($names->contains('Hidden'));
        $this->assertSame(42, $response->json('products.0.stock_qty'));
        $this->assertSame($this->store->id, $response->json('store_id'));
    }

    public function test_status_reports_pending_before_sync_and_synced_after(): void
    {
        $product = $this->stockedProduct(10);
        $payload = $this->orderPayload([$this->line($product, 1)]);
        $uuid = $payload['client_uuid'];

        $this->actingAs($this->cashier)
            ->getJson(route('pos.data.orders.status', $uuid))
            ->assertNotFound()
            ->assertJsonPath('status', 'pending');

        $this->sync([$payload])->assertOk();

        $this->actingAs($this->cashier)
            ->getJson(route('pos.data.orders.status', $uuid))
            ->assertOk()
            ->assertJsonPath('status', 'synced');
    }

    public function test_sync_endpoints_are_closed_to_guests(): void
    {
        $this->getJson(route('pos.data.products'))->assertUnauthorized();
        $this->postJson(route('pos.data.orders.sync'), ['orders' => []])->assertUnauthorized();
    }

    private function stockQty(Product $product): int
    {
        return (int) Stock::where('product_id', $product->id)
            ->where('store_id', $this->store->id)
            ->value('qty');
    }
}
