<?php

namespace Tests\Feature;

use App\Enums\Action;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class VendorTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $admin;

    private Vendor $vendor;

    private User $vendorUser;

    /** The vendor's own shop, as the Vendors screen would create it. */
    private Store $vendorStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->vendor = Vendor::factory()->create(['name' => 'Angkor Drinks']);
        $this->vendorUser = User::factory()->create([
            'role' => Role::Vendor,
            'vendor_id' => $this->vendor->id,
            'store_id' => null,
            'is_active' => true,
        ]);
        $this->vendorStore = Store::factory()->create(['vendor_id' => $this->vendor->id]);
    }

    private function cashierPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Cashier',
            'email' => 'new.cashier@example.com',
            'password' => 'Str0ng!Passw0rd#2026',
            'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'role' => 'cashier',
            'store_id' => $this->vendorStore->id,
            'is_active' => true,
        ], $overrides);
    }

    /* ------------------------------------------------------------------ */
    /* The vendor role */
    /* ------------------------------------------------------------------ */

    public function test_a_vendor_runs_the_shop_but_not_the_audit_trail_or_the_vendor_list(): void
    {
        foreach ([Permission::Pos, Permission::Products, Permission::Reports, Permission::Users] as $p) {
            $this->assertTrue($this->vendorUser->hasPermission($p), $p->value);
        }

        $this->assertFalse($this->vendorUser->hasPermission(Permission::Activity));
        $this->assertFalse($this->vendorUser->hasPermission(Permission::Vendors));

        $this->actingAs($this->vendorUser)->get('/vendors')->assertForbidden();
        $this->actingAs($this->vendorUser)->get('/activity')->assertForbidden();
    }

    public function test_a_vendor_has_every_action_on_its_own_data_only(): void
    {
        $this->assertTrue($this->vendorUser->mayDo(Permission::Products, Action::Delete));

        $own = Product::factory()->create(['vendor_id' => $this->vendor->id]);
        $shops = Product::factory()->create();

        // Another side's row does not exist as far as a vendor can tell.
        $this->actingAs($this->vendorUser)
            ->delete(route('products.destroy', ['product' => $shops->uuid]))
            ->assertNotFound();

        $this->actingAs($this->vendorUser)
            ->delete(route('products.destroy', ['product' => $own->uuid]))
            ->assertRedirect();

        $this->assertModelExists($shops);
    }

    public function test_a_vendor_account_must_name_its_vendor(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->cashierPayload(['role' => 'vendor', 'store_id' => null]))
            ->assertSessionHasErrors('vendor_id');

        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->cashierPayload(['role' => 'vendor', 'store_id' => null, 'vendor_id' => $this->vendor->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->vendor->id, User::where('email', 'new.cashier@example.com')->value('vendor_id'));
    }

    /* ------------------------------------------------------------------ */
    /* Vendors hire cashiers */
    /* ------------------------------------------------------------------ */

    public function test_a_vendor_can_create_a_cashier_on_its_own_team(): void
    {
        $other = Vendor::factory()->create();

        // Even naming another vendor, the hire lands on the creator's team.
        $this->actingAs($this->vendorUser)
            ->post(route('users.store'), $this->cashierPayload(['vendor_id' => $other->id]))
            ->assertSessionHasNoErrors();

        $hire = User::where('email', 'new.cashier@example.com')->firstOrFail();
        $this->assertSame(Role::Cashier, $hire->role);
        $this->assertSame($this->vendor->id, $hire->vendor_id);
    }

    public function test_a_vendor_can_create_nothing_but_cashiers(): void
    {
        foreach (['manager', 'vendor', 'admin'] as $role) {
            $this->actingAs($this->vendorUser)
                ->post(route('users.store'), $this->cashierPayload(['role' => $role, 'vendor_id' => $this->vendor->id]))
                ->assertSessionHasErrors('role');
        }

        $this->assertDatabaseMissing('users', ['email' => 'new.cashier@example.com']);
    }

    public function test_a_vendor_sees_and_edits_only_its_own_cashiers(): void
    {
        $own = User::factory()->cashier($this->vendorStore)->create(['vendor_id' => $this->vendor->id]);
        $shopCashier = User::factory()->cashier($this->store)->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($this->vendorUser)
            ->get('/users')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('users.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$this->vendorUser->id, $own->id])->sort()->values()->all())
                ->where('roles', fn ($roles) => collect($roles)->pluck('value')->all() === ['cashier']));

        $edit = fn (User $u) => $this->actingAs($this->vendorUser)
            ->put(route('users.update', ['user' => $u->uuid]), [
                'name' => 'Renamed',
                'email' => $u->email,
                'role' => 'cashier',
                'store_id' => $this->vendorStore->id,
                'is_active' => true,
            ]);

        $edit($own)->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $own->fresh()->name);

        $edit($shopCashier)->assertForbidden();
        $edit($manager)->assertForbidden();
    }

    public function test_a_vendor_cannot_hand_out_permissions(): void
    {
        $own = User::factory()->cashier($this->store)->create(['vendor_id' => $this->vendor->id]);

        $this->actingAs($this->vendorUser)
            ->put(route('users.permissions', ['user' => $own->uuid]), [
                'permissions' => ['reports' => array_fill_keys(Action::values(), true)],
            ])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* The vendor screen */
    /* ------------------------------------------------------------------ */

    private const PASSWORD = 'Str0ng!Passw0rd#2026';

    public function test_creating_a_vendor_creates_its_login(): void
    {
        $this->actingAs($this->admin)
            ->post(route('vendors.store'), [
                'name' => 'Mekong Snacks',
                'email' => 'owner@mekong.test',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->assertSessionHasNoErrors();

        $vendor = Vendor::where('name', 'Mekong Snacks')->firstOrFail();
        $owner = $vendor->owner;

        // Somewhere to sell from, with a till.
        $store = Store::ofVendor($vendor->id)->sole();
        $this->assertSame(1, $store->registers()->count());

        $this->assertNotNull($owner);
        $this->assertSame(Role::Vendor, $owner->role);
        $this->assertSame('owner@mekong.test', $owner->email);
        $this->assertTrue($owner->is_active);

        auth()->logout();
        $this->post(route('login'), ['email' => 'owner@mekong.test', 'password' => self::PASSWORD]);
        $this->assertAuthenticatedAs($owner);
    }

    public function test_a_vendor_needs_a_password_and_a_free_email(): void
    {
        $this->actingAs($this->admin)
            ->post(route('vendors.store'), ['name' => 'No Login', 'email' => $this->admin->email])
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertDatabaseMissing('vendors', ['name' => 'No Login']);
    }

    public function test_editing_keeps_the_password_unless_a_new_one_is_typed(): void
    {
        $vendor = Vendor::factory()->create();
        $owner = User::factory()->create(['role' => Role::Vendor, 'vendor_id' => $vendor->id, 'password' => self::PASSWORD]);
        $hash = $owner->password;

        $this->actingAs($this->admin)
            ->put(route('vendors.update', ['vendor' => $vendor->uuid]), ['name' => 'Renamed', 'email' => 'new@login.test'])
            ->assertSessionHasNoErrors();

        $owner->refresh();
        $this->assertSame('new@login.test', $owner->email);
        $this->assertSame($hash, $owner->password);

        $this->actingAs($this->admin)
            ->put(route('vendors.update', ['vendor' => $vendor->uuid]), [
                'name' => 'Renamed',
                'email' => 'new@login.test',
                'password' => 'An0ther!Passw0rd#99',
                'password_confirmation' => 'An0ther!Passw0rd#99',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotSame($hash, $owner->fresh()->password);
    }

    public function test_switching_a_vendor_off_locks_out_its_whole_team(): void
    {
        $cashier = User::factory()->cashier($this->store)->create(['vendor_id' => $this->vendor->id]);

        $this->actingAs($this->admin)
            ->put(route('vendors.update', ['vendor' => $this->vendor->uuid]), [
                'name' => $this->vendor->name,
                'email' => $this->vendorUser->email,
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->vendorUser->fresh()->is_active);
        $this->assertFalse($cashier->fresh()->is_active);
    }

    public function test_deleting_an_empty_vendor_takes_its_login_and_store(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('vendors.destroy', ['vendor' => $this->vendor->uuid]))
            ->assertRedirect(route('vendors.index'));

        $this->assertModelMissing($this->vendor);
        $this->assertModelMissing($this->vendorUser);
        $this->assertModelMissing($this->vendorStore);
    }

    public function test_a_vendor_with_products_cannot_be_deleted(): void
    {
        Product::factory()->create(['vendor_id' => $this->vendor->id]);

        $this->actingAs($this->admin)
            ->delete(route('vendors.destroy', ['vendor' => $this->vendor->uuid]))
            ->assertSessionHasErrors('vendor');

        $this->assertModelExists($this->vendor);
    }

    public function test_a_vendor_with_cashiers_cannot_be_deleted(): void
    {
        User::factory()->cashier($this->vendorStore)->create(['vendor_id' => $this->vendor->id]);

        $this->actingAs($this->admin)
            ->delete(route('vendors.destroy', ['vendor' => $this->vendor->uuid]))
            ->assertSessionHasErrors('vendor');

        $this->assertModelExists($this->vendor);
    }

    public function test_the_summary_credits_packs_to_their_vendor(): void
    {
        $can = Product::factory()->create(['vendor_id' => $this->vendor->id, 'name' => 'Beer can']);
        $case = Product::factory()->create([
            'vendor_id' => null,
            'parent_product_id' => $can->id,
            'units_per_pack' => 24,
            'name' => 'Beer case',
        ]);
        $unrelated = Product::factory()->create();

        $order = Order::create([
            'client_uuid' => (string) Str::uuid(),
            'order_no' => 'NO-'.Str::random(8),
            'store_id' => $this->store->id,
            'cashier_id' => $this->admin->id,
            'subtotal' => '130.00',
            'total' => '130.00',
            'paid_amount' => '130.00',
            'status' => OrderStatus::Completed,
            'synced_at' => now(),
        ]);

        foreach ([[$can, 2, '10.00'], [$case, 1, '100.00'], [$unrelated, 1, '20.00']] as [$p, $qty, $subtotal]) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $p->id,
                'product_name' => $p->name,
                'unit_price' => $subtotal,
                'qty' => $qty,
                'subtotal' => $subtotal,
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('vendors.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Vendors/Index')
                ->where('vendors.data.0.sales.revenue', '110.00')
                ->where('vendors.data.0.sales.orders', 1)
                ->where('summary.revenue', '110.00'));

        $this->actingAs($this->admin)
            ->get(route('vendors.show', ['vendor' => $this->vendor->uuid]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Vendors/Show')
                ->has('products', 1)
                // 2 cans + one case of 24, in base units.
                ->where('products.0.sold', 26)
                ->where('totals.revenue', '110.00'));
    }
}
