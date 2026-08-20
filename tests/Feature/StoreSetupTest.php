<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreSetupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Creating a product seeds a stock row per store. The mirror case was
     * missing: a store created after the catalogue already existed had no
     * rows at all, so its POS showed zero for every product and its shelves
     * never appeared in the low-stock report.
     */
    public function test_a_new_store_gets_a_stock_row_for_every_existing_product(): void
    {
        Product::factory()->count(3)->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('stores.store'), ['name' => 'Second Branch'])
            ->assertRedirect();

        $store = Store::where('name', 'Second Branch')->firstOrFail();

        $this->assertSame(3, Stock::where('store_id', $store->id)->count());

        // Zero, not a copy of another store's count — goods are received
        // through an inventory movement, not by creating a store.
        $this->assertSame(0, (int) Stock::where('store_id', $store->id)->sum('qty'));
    }

    public function test_creating_a_store_with_no_products_yet_is_fine(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('stores.store'), ['name' => 'Empty Branch'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('stores', ['name' => 'Empty Branch']);
    }

    /** Both directions must hold: a product added later reaches every store. */
    public function test_a_product_created_later_reaches_every_store(): void
    {
        $a = Store::factory()->create();
        $b = Store::factory()->create();
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $this->actingAs($admin)->post(route('products.store'), [
            'category_id' => $category->id,
            'name' => 'Late Arrival',
            'sku' => 'LATE-1',
            'cost_price' => '1.00',
            'sell_price' => '2.00',
            'unit' => 'pcs',
            'opening_qty' => 7,
        ])->assertRedirect();

        $product = Product::where('sku', 'LATE-1')->firstOrFail();

        foreach ([$a, $b] as $store) {
            $this->assertDatabaseHas('stocks', [
                'product_id' => $product->id,
                'store_id' => $store->id,
                'qty' => 7,
            ]);
        }
    }
}
