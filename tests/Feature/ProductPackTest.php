<?php

namespace Tests\Feature;

use App\Enums\InventoryLogType;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Register;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Selling one thing several ways.
 *
 * A case of beer is 24 cans on the same shelf, so the whole feature rests on
 * one invariant: however it was sold, the stock that moves is the base
 * product's, in base units.
 */
class ProductPackTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Register $register;

    private User $cashier;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->register = Register::factory()->create(['store_id' => $this->store->id]);
        $this->cashier = User::factory()->cashier($this->store)->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function can(int $qty = 120): Product
    {
        $product = Product::factory()->create(['name' => 'Beer 330ml', 'unit' => 'can', 'sell_price' => '0.75']);

        Stock::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'qty' => $qty]);

        return $product;
    }

    private function packOf(Product $base, int $units, string $name, string $price): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'parent_product_id' => $base->id,
            'units_per_pack' => $units,
            'sell_price' => $price,
            'unit' => 'pack',
        ]);
    }

    private function sell(Product $product, int $qty): void
    {
        $this->actingAs($this->cashier)->postJson(route('pos.data.orders.sync'), [
            'orders' => [[
                'client_uuid' => (string) Str::uuid(),
                'register_id' => $this->register->id,
                'customer_id' => null,
                'created_offline_at' => now()->toIso8601String(),
                'discount_amount' => '0.00',
                'items' => [[
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'qty' => $qty,
                    'unit_price' => $product->sell_price,
                    'discount' => '0.00',
                    'tax_rate' => 0,
                ]],
                'payments' => [['method' => 'cash', 'amount' => '1000.00', 'reference_no' => null]],
            ]],
        ])->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* The invariant */
    /* ------------------------------------------------------------------ */

    public function test_selling_a_case_takes_its_units_off_the_base_products_shelf(): void
    {
        $can = $this->can(120);
        $case = $this->packOf($can, 24, 'Beer — case of 24', '16.00');

        $this->sell($case, 1);

        $this->assertSame(96, Stock::where('product_id', $can->id)->value('qty'));

        // The pack itself never grows a shelf of its own.
        $this->assertDatabaseMissing('stocks', ['product_id' => $case->id]);
    }

    public function test_every_pack_size_draws_down_the_same_shelf(): void
    {
        $can = $this->can(120);
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');
        $half = $this->packOf($can, 12, 'Half case', '8.40');
        $six = $this->packOf($can, 6, 'Six-pack', '4.32');

        $this->sell($case, 1);   // -24
        $this->sell($half, 1);   // -12
        $this->sell($six, 2);    // -12
        $this->sell($can, 3);    //  -3

        $this->assertSame(69, Stock::where('product_id', $can->id)->value('qty'));
    }

    /** The ledger has to name the shelf that moved, not the row that was rung up. */
    public function test_the_movement_is_logged_against_the_base_product_in_base_units(): void
    {
        $can = $this->can(120);
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->sell($case, 2);

        $log = InventoryLog::where('type', InventoryLogType::Sale)->latest('id')->first();

        $this->assertSame($can->id, $log->product_id);
        $this->assertSame(-48, $log->qty_change);
    }

    /* ------------------------------------------------------------------ */
    /* Creating them */
    /* ------------------------------------------------------------------ */

    public function test_creating_a_pack_does_not_seed_stock_rows(): void
    {
        $can = $this->can(50);

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $can->category_id,
            'parent_product_id' => $can->id,
            'units_per_pack' => 24,
            'name' => 'Beer — case of 24',
            'sku' => 'BEER-CASE',
            'sell_price' => '16.00',
            'unit' => 'case',
            'track_stock' => true,
            'is_active' => true,
            'opening_qty' => 99,
        ])->assertRedirect();

        $case = Product::where('sku', 'BEER-CASE')->firstOrFail();

        $this->assertSame($can->id, $case->parent_product_id);
        $this->assertSame(24, $case->units_per_pack);
        $this->assertDatabaseMissing('stocks', ['product_id' => $case->id]);
    }

    /** One level only: the base-unit maths stops making sense beyond it. */
    public function test_a_pack_cannot_belong_to_another_pack(): void
    {
        $can = $this->can();
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $can->category_id,
            'parent_product_id' => $case->id,
            'units_per_pack' => 2,
            'name' => 'Two cases',
            'sku' => 'BEER-2CASE',
            'sell_price' => '31.00',
            'unit' => 'pallet',
            'track_stock' => true,
            'is_active' => true,
        ])->assertSessionHasErrors('parent_product_id');
    }

    public function test_a_pack_must_say_how_many_units_it_holds(): void
    {
        $can = $this->can();

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $can->category_id,
            'parent_product_id' => $can->id,
            'name' => 'Beer — case',
            'sku' => 'BEER-CASE-2',
            'sell_price' => '16.00',
            'unit' => 'case',
            'track_stock' => true,
            'is_active' => true,
        ])->assertSessionHasErrors('units_per_pack');
    }

    public function test_a_base_product_with_packs_cannot_be_deleted(): void
    {
        $can = $this->can();
        $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->actingAs($this->admin)
            ->delete(route('products.destroy', $can))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $can->id]);
    }

    public function test_a_store_created_later_gets_no_stock_rows_for_packs(): void
    {
        $can = $this->can();
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->actingAs($this->admin)
            ->post(route('stores.store'), ['name' => 'Second Store'])
            ->assertRedirect();

        $second = Store::where('name', 'Second Store')->firstOrFail();

        $this->assertDatabaseHas('stocks', ['product_id' => $can->id, 'store_id' => $second->id]);
        $this->assertDatabaseMissing('stocks', ['product_id' => $case->id]);
    }

    /* ------------------------------------------------------------------ */
    /* How they are shown */
    /* ------------------------------------------------------------------ */

    /** The till needs pack stock in packs — 96 cans is 4 cases, not 96. */
    public function test_the_pos_feed_reports_pack_stock_in_whole_packs(): void
    {
        $can = $this->can(99);
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $feed = $this->actingAs($this->cashier)
            ->getJson(route('pos.data.products'))
            ->assertOk()
            ->json('products');

        $rows = collect($feed)->keyBy('id');

        $this->assertSame(99, $rows[$can->id]['stock_qty']);
        $this->assertSame(4, $rows[$case->id]['stock_qty']);
        $this->assertSame(24, $rows[$case->id]['units_per_pack']);
    }

    public function test_the_menu_lists_packs_under_their_product_rather_than_separately(): void
    {
        $can = $this->can();
        $this->packOf($can, 24, 'Case of 24', '16.00');

        $products = $this->get(route('menu'))
            ->assertOk()
            ->viewData('page')['props']['products'];

        $this->assertCount(1, $products);
        $this->assertSame('Beer 330ml', $products[0]['name']);
        $this->assertCount(1, $products[0]['packs']);
        $this->assertSame('Case of 24', $products[0]['packs'][0]['name']);
    }
}
