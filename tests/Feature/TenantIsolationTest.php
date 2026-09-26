<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Register;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Multi-vendor: each vendor's data is its own, the admin's shop is its own,
 * and only an admin sees across them. See App\Support\Tenant.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vendor $vendor;

    private User $vendorUser;

    private Store $shopStore;

    private Store $vendorStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->shopStore = Store::factory()->create(['name' => 'Main shop']);

        $this->vendor = Vendor::factory()->create();
        $this->vendorUser = User::factory()->create(['role' => Role::Vendor, 'vendor_id' => $this->vendor->id]);
        $this->vendorStore = Store::factory()->create(['vendor_id' => $this->vendor->id, 'name' => 'Vendor shop']);
    }

    private function productOf(?int $vendorId, string $name): Product
    {
        return Product::factory()->create([
            'vendor_id' => $vendorId,
            'category_id' => Category::factory()->create(['vendor_id' => $vendorId])->id,
            'name' => $name,
        ]);
    }

    private function saleIn(Store $store, string $total): Order
    {
        return Order::create([
            'client_uuid' => (string) Str::uuid(),
            'order_no' => 'NO-'.Str::random(8),
            'store_id' => $store->id,
            'cashier_id' => $this->admin->id,
            'subtotal' => $total,
            'total' => $total,
            'paid_amount' => $total,
            'status' => OrderStatus::Completed,
            'synced_at' => now(),
        ]);
    }

    /** @return string[] */
    private function productNamesSeenBy(User $user): array
    {
        $names = [];

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertInertia(function (AssertableInertia $page) use (&$names) {
                $names = collect($page->toArray()['props']['products']['data'])->pluck('name')->sort()->values()->all();
            });

        return $names;
    }

    public function test_a_vendor_sees_only_its_own_catalogue(): void
    {
        $this->productOf(null, 'Shop coffee');
        $this->productOf($this->vendor->id, 'Vendor beer');

        $this->assertSame(['Vendor beer'], $this->productNamesSeenBy($this->vendorUser));
    }

    public function test_the_admin_shops_staff_never_see_a_vendors_catalogue(): void
    {
        $this->productOf(null, 'Shop coffee');
        $this->productOf($this->vendor->id, 'Vendor beer');

        $manager = User::factory()->manager()->create();

        $this->assertSame(['Shop coffee'], $this->productNamesSeenBy($manager));
    }

    public function test_an_admin_sees_everything_and_can_narrow_to_one_side(): void
    {
        $this->productOf(null, 'Shop coffee');
        $this->productOf($this->vendor->id, 'Vendor beer');

        $this->assertSame(['Shop coffee', 'Vendor beer'], $this->productNamesSeenBy($this->admin));

        $this->actingAs($this->admin)->put(route('viewing.update'), ['viewing' => (string) $this->vendor->id]);
        $this->assertSame(['Vendor beer'], $this->productNamesSeenBy($this->admin));

        $this->actingAs($this->admin)->put(route('viewing.update'), ['viewing' => 'shop']);
        $this->assertSame(['Shop coffee'], $this->productNamesSeenBy($this->admin));

        $this->actingAs($this->admin)->put(route('viewing.update'), ['viewing' => 'all']);
        $this->assertSame(['Shop coffee', 'Vendor beer'], $this->productNamesSeenBy($this->admin));
    }

    public function test_only_an_admin_has_the_viewing_switcher(): void
    {
        $this->actingAs($this->vendorUser)
            ->put(route('viewing.update'), ['viewing' => 'all'])
            ->assertForbidden();
    }

    public function test_what_a_vendor_creates_is_its_own(): void
    {
        $this->actingAs($this->vendorUser)
            ->post(route('categories.store'), ['name' => 'Drinks'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->vendorUser)
            ->post(route('customers.store'), ['name' => 'Dara'])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->vendor->id, Category::withoutGlobalScopes()->where('name', 'Drinks')->value('vendor_id'));
        $this->assertSame($this->vendor->id, Customer::withoutGlobalScopes()->where('name', 'Dara')->value('vendor_id'));
    }

    public function test_a_vendor_cannot_file_a_product_under_the_shops_category(): void
    {
        $shopCategory = Category::factory()->create();

        $this->actingAs($this->vendorUser)
            ->post(route('products.store'), [
                'category_id' => $shopCategory->id,
                'name' => 'Sneaky',
                'sku' => 'SNEAK-1',
                'sell_price' => '1000',
                'unit' => 'pcs',
            ])
            ->assertSessionHasErrors('category_id');
    }

    public function test_orders_and_reports_are_split_by_side(): void
    {
        $this->saleIn($this->shopStore, '500.00');
        $this->saleIn($this->vendorStore, '70.00');

        $this->actingAs($this->vendorUser)
            ->get(route('orders.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 1)->where('orders.data.0.total', '70.00'));

        $this->actingAs($this->vendorUser)
            ->get(route('reports.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.sales', '70.00'));

        $this->actingAs($this->admin)
            ->get(route('reports.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.sales', '570.00'));
    }

    public function test_a_vendor_cannot_ring_a_sale_into_the_shops_till(): void
    {
        $shopRegister = Register::factory()->create(['store_id' => $this->shopStore->id]);
        $shopProduct = $this->productOf(null, 'Shop coffee');

        $this->actingAs($this->vendorUser)
            ->postJson(route('pos.data.orders.sync'), ['orders' => [[
                'client_uuid' => (string) Str::uuid(),
                'store_id' => $this->shopStore->id,
                'register_id' => $shopRegister->id,
                'created_offline_at' => now()->toIso8601String(),
                'discount_amount' => '0.00',
                'items' => [[
                    'product_id' => $shopProduct->id,
                    'product_name' => $shopProduct->name,
                    'qty' => 1,
                    'unit_price' => $shopProduct->sell_price,
                    'discount' => '0.00',
                ]],
                'payments' => [['method' => 'cash', 'amount' => '1000.00', 'reference_no' => null]],
            ]]])
            ->assertUnprocessable();

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_a_vendor_manager_cannot_reach_the_shops_staff(): void
    {
        $shopCashier = User::factory()->cashier($this->shopStore)->create();

        $this->actingAs($this->vendorUser)
            ->delete(route('users.destroy', ['user' => $shopCashier->uuid]))
            ->assertForbidden();
    }

    public function test_each_side_has_its_own_public_menu(): void
    {
        $this->productOf(null, 'Shop coffee');
        $this->productOf($this->vendor->id, 'Vendor beer');

        $names = fn ($response) => collect($response->viewData('page')['props']['products'])->pluck('name')->all();

        $this->assertSame(['Shop coffee'], $names($this->get(route('menu'))));
        $this->assertSame(['Vendor beer'], $names($this->get(route('menu', ['vendor' => $this->vendor->uuid]))));
    }
}
