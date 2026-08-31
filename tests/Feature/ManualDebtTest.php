<?php

namespace Tests\Feature;

use App\Enums\SaleType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\SalesReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Debt typed straight onto a customer, without ringing products.
 */
class ManualDebtTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->customer = Customer::create(['name' => 'GoJo', 'phone' => '090111222']);
    }

    private function add(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(route('debts.store'), array_merge([
            'customer_id' => $this->customer->id,
            'amount' => '10000',
            'note' => 'rice + oil',
        ], $overrides));
    }

    public function test_an_amount_becomes_a_debt_order_with_nothing_paid(): void
    {
        $this->add()->assertRedirect()->assertSessionHasNoErrors();

        $order = Order::firstOrFail();

        $this->assertSame(SaleType::Debt, $order->sale_type);
        $this->assertSame($this->customer->id, $order->customer_id);
        $this->assertSame('10000.00', $order->total);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertSame('10000.00', $order->outstanding());

        // A real order number, from the same sequence as till sales.
        $this->assertMatchesRegularExpression('/^S\d+-R\d+-\d{6}-\d{4}$/', $order->order_no);
    }

    /** The note is the line: the debt detail says what the money was for. */
    public function test_the_note_becomes_the_line_name(): void
    {
        $this->add();

        $item = Order::firstOrFail()->items()->firstOrFail();

        $this->assertNull($item->product_id);
        $this->assertSame('rice + oil', $item->product_name);
        $this->assertSame(1, $item->qty);
    }

    public function test_no_note_falls_back_to_a_plain_label(): void
    {
        $this->add(['note' => '']);

        $this->assertSame('Goods on credit', Order::firstOrFail()->items()->value('product_name'));
    }

    /** No products were rung, so no shelf may move. */
    public function test_a_manual_debt_never_touches_stock(): void
    {
        $this->add();

        $this->assertDatabaseCount('inventory_logs', 0);
    }

    /** Goods left the shop that day, so that day's sales include it. */
    public function test_it_counts_as_revenue_on_the_day(): void
    {
        Setting::put('currency', 'KHR');

        $this->add(['amount' => '10000']);

        $summary = (new SalesReporter($this->store->id))
            ->summaryFor(SalesReporter::businessNow()->startOfDay());

        $this->assertSame(10000.0, (float) $summary['sales']);
    }

    /** And the existing settle flow closes it, unchanged. */
    public function test_the_ordinary_settle_flow_pays_it_off(): void
    {
        $this->add(['amount' => '10000']);
        $order = Order::firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('debts.settle', $order), ['amount' => '10000', 'method' => 'cash'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('0.00', $order->fresh()->outstanding());
    }

    public function test_the_customer_must_exist_and_the_amount_be_positive(): void
    {
        $this->add(['customer_id' => 9999])->assertSessionHasErrors('customer_id');
        $this->add(['amount' => '0'])->assertSessionHasErrors('amount');
        $this->add(['amount' => '-5'])->assertSessionHasErrors('amount');
    }

    public function test_a_cashier_cannot_reach_it(): void
    {
        $cashier = User::factory()->cashier($this->store)->create();

        $this->actingAs($cashier)->post(route('debts.store'), [
            'customer_id' => $this->customer->id,
            'amount' => '5000',
        ])->assertForbidden();
    }
}
