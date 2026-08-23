<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PublicMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_view_the_menu_without_logging_in(): void
    {
        Product::factory()->create(['name' => 'Iced Coffee']);

        $this->get(route('menu'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Menu/Index')
                ->has('products', 1)
                ->where('products.0.name', 'Iced Coffee')
            );

        $this->assertGuest();
    }

    public function test_inactive_products_are_hidden_from_the_public_menu(): void
    {
        Product::factory()->create(['name' => 'On Sale']);
        Product::factory()->inactive()->create(['name' => 'Discontinued']);

        $this->get(route('menu'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('products', 1)
                ->where('products.0.name', 'On Sale')
            );
    }

    /**
     * Prices are stored tax-exclusive. Quoting the net figure on a customer
     * menu would understate what they actually pay at the till.
     */
    public function test_menu_prices_include_tax(): void
    {
        Product::factory()->create([
            'name' => 'Taxed',
            'sell_price' => '10.00',
            'tax_rate' => '10.00',
        ]);

        // JSON renders 11.0 as `11`, so compare numerically rather than by
        // strict type — this is a money value, not an integer.
        $this->assertEqualsWithDelta(11.0, $this->firstMenuPrice(), 0.001);
    }

    /** An explicit 0.00 is zero-rated and the default never overrides it. */
    public function test_an_explicitly_zero_rated_product_is_not_taxed(): void
    {
        Product::factory()->taxFree()->create(['name' => 'Untaxed', 'sell_price' => '10.00']);

        $this->assertEqualsWithDelta(10.0, $this->firstMenuPrice(), 0.001);
    }

    /**
     * Tax is no longer edited per product, so a product with no rate of its
     * own inherits default_tax_rate. This is the rule the single-price form
     * depends on — without it every new product would be tax-free.
     */
    public function test_a_product_with_no_rate_inherits_the_default(): void
    {
        Setting::put('default_tax_rate', '10.00');

        Product::factory()->inheritsTax()->create(['name' => 'Inherits', 'sell_price' => '10.00']);

        $this->assertEqualsWithDelta(11.0, $this->firstMenuPrice(), 0.001);
    }

    public function test_with_no_default_configured_an_unrated_product_is_untaxed(): void
    {
        Setting::query()->where('key', 'default_tax_rate')->delete();
        Setting::put('currency_symbol', '$'); // bust the settings cache

        Product::factory()->inheritsTax()->create(['name' => 'No default', 'sell_price' => '10.00']);

        $this->assertEqualsWithDelta(10.0, $this->firstMenuPrice(), 0.001);
    }

    private function firstMenuPrice(): float
    {
        $response = $this->get(route('menu'))->assertOk();

        return (float) $response->viewData('page')['props']['products'][0]['price'];
    }

    /** The menu must never leak cost price, stock levels or staff data. */
    public function test_menu_does_not_expose_internal_data(): void
    {
        $product = Product::factory()->create(['cost_price' => '3.33']);

        $response = $this->get(route('menu'))->assertOk();

        $payload = $response->viewData('page')['props']['products'][0];

        $this->assertArrayNotHasKey('cost_price', $payload);
        $this->assertArrayNotHasKey('stock_qty', $payload);
        $this->assertArrayNotHasKey('sku', $payload);
        $this->assertArrayNotHasKey('barcode', $payload);
        $this->assertStringNotContainsString('3.33', json_encode($payload));
        $this->assertSame($product->id, $payload['id']);
    }

    public function test_only_categories_with_visible_products_are_listed(): void
    {
        $shown = Category::factory()->create(['name' => 'Drinks']);
        Category::factory()->create(['name' => 'Empty Shelf']);

        Product::factory()->create(['category_id' => $shown->id]);

        $this->get(route('menu'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('categories', 1)
                ->where('categories.0.name', 'Drinks')
            );
    }

    public function test_signed_in_staff_can_also_view_the_menu(): void
    {
        Store::factory()->create();
        Product::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('menu'))
            ->assertOk();
    }

    /**
     * The way back into the admin must exist for staff and not for customers —
     * this route is the one an anonymous QR scan lands on.
     */
    public function test_the_dashboard_link_is_only_rendered_for_signed_in_staff(): void
    {
        $this->get(route('menu'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user', null));

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('menu'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.id', $admin->id));
    }
}
