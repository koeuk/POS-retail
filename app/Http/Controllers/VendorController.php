<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\VendorRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Vendor;
use App\Services\SalesReporter;
use App\Support\PerPage;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class VendorController extends Controller
{
    /** The summary windows on offer, in days. */
    private const WINDOWS = [7, 30, 90];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vendor::class);

        // The admin's cross-vendor view: whatever the "Viewing" switcher is
        // set to, this screen compares every vendor.
        return Tenant::unscoped(fn () => $this->renderIndex($request));
    }

    private function renderIndex(Request $request): Response
    {
        [$days, $from, $to] = $this->window($request);
        $sales = SalesReporter::for($request->user())->salesByVendor($from, $to);

        $vendors = QueryBuilder::for(Vendor::class)
            // Base products only: a pack is a way of selling one, not another.
            ->with(['owner' => self::ownerColumns(...)])
            ->withCount(['products' => fn (Builder $q) => $q->whereNull('parent_product_id'), 'users'])
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, string $search) {
                    $query->where(function (Builder $q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('contact_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                }),
            ])
            ->orderBy('name')
            ->paginate(PerPage::resolve($request))
            ->withQueryString()
            ->through(fn (Vendor $v) => array_merge($v->toArray(), [
                'sales' => $sales[$v->id] ?? ['orders' => 0, 'qty' => 0, 'revenue' => '0.00'],
            ]));

        return Inertia::render('Vendors/Index', [
            'vendors' => $vendors,
            'summary' => [
                'vendors' => Vendor::count(),
                'active' => Vendor::where('is_active', true)->count(),
                'products' => Product::whereNotNull('vendor_id')->whereNull('parent_product_id')->count(),
                'revenue' => number_format((float) $sales->sum(fn ($s) => (float) $s['revenue']), 2, '.', ''),
            ],
            'days' => $days,
            'windows' => self::WINDOWS,
            'filters' => ['search' => (string) $request->input('filter.search', '')],
        ]);
    }

    /** One supplier at a glance: what it supplies, what is left, what sold. */
    public function show(Request $request, Vendor $vendor): Response
    {
        $this->authorize('view', $vendor);

        return Tenant::unscoped(fn () => $this->renderShow($request, $vendor));
    }

    private function renderShow(Request $request, Vendor $vendor): Response
    {
        [$days, $from, $to] = $this->window($request);
        $sales = SalesReporter::for($request->user())->vendorProductSales($vendor->id, $from, $to);

        $products = $vendor->products()
            ->whereNull('parent_product_id')
            ->withSum('stocks as on_hand', 'qty')
            ->orderBy('name')
            ->get(['id', 'uuid', 'vendor_id', 'name', 'sku', 'unit', 'cost_price', 'sell_price', 'is_active'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'name' => $p->name,
                'sku' => $p->sku,
                'unit' => $p->unit,
                'cost_price' => $p->cost_price,
                'sell_price' => $p->sell_price,
                'is_active' => $p->is_active,
                'on_hand' => (int) $p->on_hand,
                'sold' => $sales[$p->id]['qty'] ?? 0,
                'revenue' => $sales[$p->id]['revenue'] ?? '0.00',
            ]);

        return Inertia::render('Vendors/Show', [
            'vendor' => $vendor->load(['owner' => self::ownerColumns(...)]),
            'products' => $products,
            'users' => $vendor->users()->orderBy('name')->get(['id', 'uuid', 'name', 'email', 'role', 'is_active']),
            'totals' => [
                'products' => $products->count(),
                'on_hand' => $products->sum('on_hand'),
                // What the shelf cost to fill — negative (oversold) stock is
                // a debt to the count, not value on the shelf.
                'stock_value' => number_format(
                    $products->sum(fn ($p) => max(0, $p['on_hand']) * (float) $p['cost_price']), 2, '.', ''),
                'sold' => $products->sum('sold'),
                'revenue' => number_format($sales->sum(fn ($s) => (float) $s['revenue']), 2, '.', ''),
            ],
            'days' => $days,
            'windows' => self::WINDOWS,
        ]);
    }

    public function store(VendorRequest $request): RedirectResponse
    {
        try {
            $this->authorize('create', Vendor::class);

            DB::transaction(function () use ($request) {
                $vendor = Vendor::create($request->safe()->except('password'));
                $vendor->users()->create($this->ownerAttributes($request, $vendor) + [
                    'role' => Role::Vendor,
                    'email_verified_at' => now(), // created by an admin, like staff
                ]);

                // Somewhere to sell from on day one: its own store and till.
                // It can add more, or rename these, on the Stores screen.
                Store::create(['vendor_id' => $vendor->id, 'name' => $vendor->name])
                    ->registers()->create(['name' => 'Register 1']);
            });

            return back()->with('success', 'Vendor added. It can sign in with that email and password.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The vendor could not be saved. Nothing was changed — try again.');
        }
    }

    public function update(VendorRequest $request, Vendor $vendor): RedirectResponse
    {
        try {
            $this->authorize('update', $vendor);

            DB::transaction(function () use ($request, $vendor) {
                $vendor->update($request->safe()->except('password'));

                $attributes = $this->ownerAttributes($request, $vendor);

                // A vendor from before logins existed gets one on its first edit.
                if ($owner = $vendor->owner) {
                    $owner->update($attributes);
                } else {
                    $vendor->users()->create($attributes + ['role' => Role::Vendor, 'email_verified_at' => now()]);
                }

                // Switching a vendor off locks out its whole team, cashiers too.
                if (! $vendor->is_active) {
                    $vendor->users()->update(['is_active' => false]);
                }
            });

            return back()->with('success', 'Vendor updated.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The vendor could not be saved. Nothing was changed — try again.');
        }
    }

    public function destroy(Vendor $vendor): RedirectResponse
    {
        try {
            $this->authorize('delete', $vendor);

            $owner = $vendor->owner;

            // The vendor's own login goes with it; anyone else on its team, or
            // a login with sales against it, would be stranded or orphaned.
            $others = $vendor->users()->when($owner, fn ($q) => $q->whereKeyNot($owner->id));

            if ($others->exists() || $owner?->orders()->exists() || $this->hasData($vendor)) {
                return back()->withErrors([
                    'vendor' => 'This vendor has cashiers, sales or catalogue data. Mark it inactive instead — that locks its accounts out.',
                ]);
            }

            $name = $vendor->name;
            DB::transaction(function () use ($vendor, $owner) {
                $owner?->delete();
                // Empty stores (the one made with the vendor) go with it.
                Store::ofVendor($vendor->id)->each(fn (Store $store) => $store->delete());
                $vendor->delete();
            });

            return redirect()->route('vendors.index')->with('success', "{$name} was deleted.");
        } catch (QueryException $e) {
            return $this->failed($e, 'The vendor could not be deleted. Nothing was changed — try again.');
        }
    }

    /**
     * The login's side of the form: sign-in email, display name, the
     * vendor's on/off switch, and a new password only when one was typed.
     *
     * @return array<string, mixed>
     */
    private function ownerAttributes(VendorRequest $request, Vendor $vendor): array
    {
        $attributes = [
            'name' => $vendor->contact_name ?: $vendor->name,
            'email' => $request->validated('email'),
            'is_active' => $vendor->is_active,
        ];

        if ($password = $request->validated('password')) {
            $attributes['password'] = Hash::make($password);
        }

        return $attributes;
    }

    /**
     * Anything the vendor built that deleting would orphan or destroy: its
     * catalogue, customers, or a sale in any of its stores.
     */
    private function hasData(Vendor $vendor): bool
    {
        return Tenant::unscoped(fn () => Product::ofVendor($vendor->id)->exists()
            || Category::ofVendor($vendor->id)->exists()
            || Customer::ofVendor($vendor->id)->exists()
            || Order::whereIn('store_id', Store::ofVendor($vendor->id)->select('id'))->exists());
    }

    /** Table-qualified: the one-of-many join brings a second `users` in. */
    private static function ownerColumns($query): void
    {
        $query->select('users.id', 'users.vendor_id', 'users.email', 'users.is_active');
    }

    /** @return array{int, Carbon, Carbon} */
    private function window(Request $request): array
    {
        $days = (int) $request->input('days', 30);
        $days = in_array($days, self::WINDOWS, true) ? $days : 30;
        $to = SalesReporter::businessNow();

        return [$days, $to->copy()->subDays($days - 1)->startOfDay(), $to];
    }
}
