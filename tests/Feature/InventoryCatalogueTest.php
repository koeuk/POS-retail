<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class InventoryCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_subcategories_can_be_created_and_assigned_to_products(): void
    {
        $parent = Category::factory()->create(['name' => 'Drinks']);
        $this->actingAs($this->admin)->post(route('categories.store'), [
            'name' => 'Soft drinks', 'parent_id' => $parent->id,
        ])->assertSessionHasNoErrors();
        $child = Category::where('name', 'Soft drinks')->firstOrFail();
        $this->assertEquals($parent->id, $child->parent_id);

        $this->actingAs($this->admin)->post(route('products.store'), $this->productData($child))
            ->assertRedirect(route('products.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['sku' => 'TEST-UNIT', 'category_id' => $child->id]);
    }

    public function test_category_tree_rejects_cycles_and_more_than_two_levels(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $other = Category::factory()->create();
        $this->actingAs($this->admin)->post(route('categories.store'), [
            'name' => 'Too deep', 'parent_id' => $child->id,
        ])->assertSessionHasErrors('parent_id');
        foreach ([$parent->id, $child->id, $other->id] as $id) {
            $this->actingAs($this->admin)->put(route('categories.update', $parent), [
                'name' => $parent->name, 'parent_id' => $id,
            ])->assertSessionHasErrors('parent_id');
        }
        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_category_with_subcategories_cannot_be_deleted(): void
    {
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);
        $this->actingAs($this->admin)->delete(route('categories.destroy', $parent))
            ->assertSessionHasErrors('category');
        $this->assertModelExists($parent);
    }

    public function test_parent_category_filter_includes_subcategory_products(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $product = Product::factory()->create(['category_id' => $child->id]);
        Product::factory()->create();
        $this->actingAs($this->admin)->get(route('products.index', ['filter' => ['category_id' => $parent->id]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('products.data', 1)->where('products.data.0.id', $product->id));
        $this->get(route('menu', ['category' => $parent->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('products', 1)->where('products.0.id', $product->id)
                ->has('categories', 2));
        $this->get(route('pos.data.products'))->assertJsonFragment(['parent_id' => $parent->id]);
    }

    public function test_multiple_category_filters_remain_supported(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $other = Category::factory()->create();
        Product::factory()->create(['category_id' => $child->id]);
        Product::factory()->create(['category_id' => $other->id]);
        Product::factory()->create();
        $this->actingAs($this->admin)->get(route('products.index', [
            'filter' => ['category_id' => $parent->id.','.$other->id],
        ]))->assertInertia(fn (AssertableInertia $page) => $page->has('products.data', 2));
    }

    public function test_admin_can_manage_unused_units_and_symbols_are_unique(): void
    {
        $this->actingAs($this->admin)->post(route('units.store'), ['name' => 'Crate', 'symbol' => 'crate'])
            ->assertSessionHasNoErrors();
        $unit = Unit::where('symbol', 'crate')->firstOrFail();
        $this->post(route('units.store'), ['name' => 'Duplicate', 'symbol' => 'crate'])->assertSessionHasErrors('symbol');
        $this->put(route('units.update', $unit), ['name' => 'Large crate', 'symbol' => 'lg-crate'])
            ->assertSessionHasNoErrors();
        $this->assertSame('lg-crate', $unit->fresh()->symbol);
        $this->delete(route('units.destroy', $unit))->assertSessionHasNoErrors();
        $this->assertModelMissing($unit);
    }

    public function test_used_unit_can_be_renamed_but_not_relabelled_or_deleted(): void
    {
        $unit = Unit::where('symbol', 'pcs')->firstOrFail();
        Product::factory()->create(['unit_id' => $unit->id, 'unit' => $unit->symbol]);
        $this->actingAs($this->admin)->put(route('units.update', $unit), ['name' => 'Pieces', 'symbol' => 'pcs'])
            ->assertSessionHasNoErrors();
        $this->put(route('units.update', $unit), ['name' => 'Boxes', 'symbol' => 'box'])->assertSessionHasErrors('symbol');
        $this->delete(route('units.destroy', $unit))->assertSessionHasErrors('unit');
        $this->assertModelExists($unit);
    }

    public function test_units_have_separate_access_and_action_permissions(): void
    {
        $cashier = User::factory()->cashier($this->store)->create();
        $this->actingAs($cashier)->get(route('units.index'))->assertForbidden();
        $manager = User::factory()->manager($this->store)->create([
            'permissions' => ['units' => ['view' => true, 'create' => false, 'update' => false, 'delete' => false]],
        ]);
        $this->actingAs($manager)->get(route('units.index'))->assertOk();
        $this->post(route('units.store'), ['name' => 'Crate', 'symbol' => 'crate'])->assertForbidden();
    }

    public function test_products_use_managed_base_units_and_product_specific_pack_conversions(): void
    {
        $category = Category::factory()->create();
        $base = Unit::where('symbol', 'bottle')->firstOrFail();
        $pack = Unit::where('symbol', 'carton')->firstOrFail();
        $this->actingAs($this->admin)->post(route('products.store'), [
            ...$this->productData($category), 'unit_id' => $base->id, 'unit' => 'wrong-client-label',
            'opening_qty' => 48,
            'packs' => [['name' => 'Carton of 24', 'unit_id' => $pack->id, 'units_per_pack' => 24, 'sell_price' => 20]],
        ])->assertSessionHasNoErrors();
        $product = Product::where('sku', 'TEST-UNIT')->firstOrFail();
        $this->assertSame('bottle', $product->unit);
        $this->assertEquals($base->id, $product->unit_id);
        $this->assertEquals(48, $product->stocks()->firstOrFail()->qty);
        $carton = $product->packs()->firstOrFail();
        $this->assertSame('carton', $carton->unit);
        $this->assertSame(48, $carton->baseUnits(2));
        $this->assertSame($product->id, $carton->stockProductId());
        $this->assertSame(0, $carton->stocks()->count());
    }

    public function test_existing_stock_cannot_silently_change_its_base_unit(): void
    {
        $product = Product::factory()->create(['unit' => 'pcs']);
        Stock::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'qty' => 24]);
        $category = $product->category;
        $this->actingAs($this->admin)->put(route('products.update', $product), [
            ...$this->productData($category), 'sku' => $product->sku,
            'unit_id' => Unit::where('symbol', 'g')->value('id'),
        ])->assertSessionHasErrors('unit_id');
        $this->assertSame('pcs', $product->fresh()->unit);
        $this->assertEquals(24, $product->stocks()->firstOrFail()->qty);
    }

    public function test_legacy_unit_strings_remain_supported_and_get_registered(): void
    {
        $category = Category::factory()->create();
        $this->actingAs($this->admin)->post(route('products.store'), [
            ...$this->productData($category), 'unit' => 'tray',
        ])->assertSessionHasNoErrors();
        $unit = Unit::where('symbol', 'tray')->firstOrFail();
        $this->assertDatabaseHas('products', ['sku' => 'TEST-UNIT', 'unit' => 'tray', 'unit_id' => $unit->id]);
    }

    public function test_migration_preserves_existing_custom_and_uppercase_units_and_stock(): void
    {
        $product = Product::factory()->create(['unit' => 'PCS']);
        $custom = Product::factory()->create(['unit' => 'tray']);
        Stock::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'qty' => 29]);
        $migration = require database_path('migrations/2026_11_20_100000_add_subcategories_and_units.php');
        $migration->down();
        $migration->up();
        $this->assertSame('PCS', $product->fresh()->unit);
        $this->assertEquals(29, $product->stocks()->firstOrFail()->qty);
        $this->assertNotNull($product->fresh()->unit_id);
        $this->assertNotNull($custom->fresh()->unit_id);
        $this->assertEquals(1, Unit::where('symbol', 'pcs')->count());
        $this->assertDatabaseHas('units', ['symbol' => 'tray']);
    }

    private function productData(Category $category): array
    {
        return [
            'name' => 'Test drink', 'sku' => 'TEST-UNIT', 'category_id' => $category->id,
            'sell_price' => 1, 'unit' => 'pcs', 'is_active' => true, 'track_stock' => true,
        ];
    }
}
