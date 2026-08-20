<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
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

        $this->get(route('menu'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.price', 11.0));
    }

    public function test_a_null_tax_rate_means_zero_percent_not_a_default(): void
    {
        Product::factory()->taxFree()->create(['name' => 'Untaxed', 'sell_price' => '10.00']);

        $this->get(route('menu'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.price', 10.0));
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
}
