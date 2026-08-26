<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Services\SalesReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function sale(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'client_uuid' => (string) Str::uuid(),
            'order_no' => 'NO-'.Str::random(8),
            'store_id' => $this->store->id,
            'register_id' => null,
            'cashier_id' => $this->admin->id,
            'customer_id' => null,
            'subtotal' => '10.00',
            'discount_amount' => '0.00',
            'total' => '11.00',
            'paid_amount' => '11.00',
            'change_amount' => '0.00',
            'status' => OrderStatus::Completed,
            'synced_at' => now(),
            'created_offline_at' => null,
        ], $attributes));
    }

    /* ------------------------------------------------------------------ */
    /* The subtle one */
    /* ------------------------------------------------------------------ */

    /**
     * A sale rung up on Monday but synced on Thursday belongs to Monday.
     * Grouping on created_at would credit the takings to the wrong day and
     * quietly make every daily figure wrong.
     */
    public function test_sales_are_bucketed_by_the_day_they_happened_not_the_day_they_synced(): void
    {
        $soldOn = now()->subDays(3)->startOfDay()->addHours(10);

        // created_at is today (the sync), created_offline_at is three days ago.
        $this->sale(['created_offline_at' => $soldOn, 'total' => '50.00']);

        $reporter = new SalesReporter($this->store->id);

        $onSaleDay = $reporter->summaryFor($soldOn->copy()->startOfDay());
        $onSyncDay = $reporter->summaryFor(now()->startOfDay());

        $this->assertSame('50.00', $onSaleDay['sales'], 'takings belong to the day of the sale');
        $this->assertSame(1, $onSaleDay['orders']);

        $this->assertSame('0.00', $onSyncDay['sales'], 'the sync day must not be credited');
        $this->assertSame(0, $onSyncDay['orders']);
    }

    public function test_a_sale_with_no_offline_stamp_falls_back_to_created_at(): void
    {
        $this->sale(['total' => '25.00']);

        $summary = (new SalesReporter($this->store->id))->summaryFor(now()->startOfDay());

        $this->assertSame('25.00', $summary['sales']);
    }

    /* ------------------------------------------------------------------ */
    /* Totals */
    /* ------------------------------------------------------------------ */

    public function test_refunded_and_void_orders_never_count_toward_sales(): void
    {
        $this->sale(['total' => '10.00']);
        $this->sale(['total' => '99.00', 'status' => OrderStatus::Refunded]);
        $this->sale(['total' => '99.00', 'status' => OrderStatus::Void]);

        $summary = (new SalesReporter($this->store->id))->summaryFor(now()->startOfDay());

        $this->assertSame('10.00', $summary['sales']);
        $this->assertSame(1, $summary['orders']);
    }

    public function test_a_manager_only_sees_their_own_store(): void
    {
        $other = Store::factory()->create();

        $this->sale(['total' => '10.00']);
        $this->sale(['total' => '77.00', 'store_id' => $other->id]);

        $manager = User::factory()->manager($this->store)->create();

        $summary = SalesReporter::for($manager)->summaryFor(now()->startOfDay());
        $this->assertSame('10.00', $summary['sales']);

        // An admin has no store binding and sees the lot.
        $all = SalesReporter::for($this->admin)->summaryFor(now()->startOfDay());
        $this->assertSame('87.00', $all['sales']);
    }

    /** A quiet day must read as zero, not vanish and shift the time axis. */
    public function test_days_with_no_sales_still_appear_in_the_series(): void
    {
        $this->sale(['total' => '10.00']);

        $rows = (new SalesReporter($this->store->id))
            ->salesByDay(now()->subDays(6)->startOfDay(), now()->startOfDay());

        $this->assertCount(7, $rows);
        $this->assertSame('0.00', $rows->first()['sales']);
        $this->assertSame('10.00', $rows->last()['sales']);
    }

    public function test_the_oversold_list_surfaces_negative_stock(): void
    {
        $product = Product::factory()->create();
        Stock::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'qty' => -4]);

        $oversold = (new SalesReporter($this->store->id))->oversold();

        $this->assertCount(1, $oversold);
        $this->assertSame(-4, $oversold->first()->qty);
    }

    /* ------------------------------------------------------------------ */
    /* Screens */
    /* ------------------------------------------------------------------ */

    public function test_the_dashboard_renders_real_figures(): void
    {
        $this->sale(['total' => '42.00']);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('today.sales', '42.00')
                ->where('today.orders', 1)
                ->has('trend', 7)
                ->has('lowStock')
                ->has('oversold')
                ->has('recentOrders', 1)
            );
    }

    public function test_reports_render_for_a_manager_but_not_a_cashier(): void
    {
        $this->sale(['total' => '15.00']);

        $this->actingAs(User::factory()->manager($this->store)->create())
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Index')
                ->where('totals.sales', '15.00')
                ->has('byDay')
                ->has('byProduct')
                ->has('byPayment')
            );

        $this->actingAs(User::factory()->cashier($this->store)->create())
            ->get(route('reports.index'))
            ->assertForbidden();
    }

    public function test_the_csv_export_streams_the_daily_series(): void
    {
        $this->sale(['total' => '33.00']);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.export', ['from' => now()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Date,Orders,Sales', $csv);
        $this->assertStringContainsString('33.00', $csv);
    }

    /** A hand-edited URL must not be able to ask for a decade of daily rows. */
    public function test_an_absurd_date_range_is_clamped(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.index', ['from' => '1990-01-01', 'to' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('byDay', 367));
    }
}
