<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Register;
use App\Models\Stock;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Store::class);

        return Inertia::render('Stores/Index', [
            'stores' => Store::query()
                ->with('registers:id,store_id,name,is_active')
                ->withCount(['users', 'orders'])
                ->orderBy('name')
                ->get(),
            'canManage' => $request->user()->isAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Store::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $store = DB::transaction(function () use ($data) {
            $store = Store::create($data);

            /*
             * Backfill a stock row for every existing product.
             *
             * Creating a product already seeds a row per store, but a store
             * created afterwards would have none — its POS would show zero
             * for the whole catalogue and its shelves would never appear in
             * the low-stock report. Quantities start at 0; goods are received
             * through an inventory movement, not by creating a store.
             */
            $rows = Product::query()
                ->pluck('id')
                ->map(fn (int $productId) => [
                    'product_id' => $productId,
                    'store_id' => $store->id,
                    'qty' => 0,
                    'low_stock_threshold' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->all();

            if ($rows !== []) {
                Stock::insert($rows);
            }

            return $store;
        });

        return back()->with(
            'success',
            "“{$store->name}” created with {$store->stocks()->count()} product rows at zero stock."
        );
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('update', $store);

        $store->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]));

        return back()->with('success', 'Store updated.');
    }

    /**
     * Deleting a store is guarded three ways, and each refusal explains
     * itself rather than letting a foreign key throw a 500 at the operator.
     *
     * Orders are the hard one: `orders.store_id` is restrictOnDelete, so the
     * database would refuse anyway — a sale must always point at a real shop.
     * Stock rows, registers and the inventory ledger cascade, which is
     * correct: they describe a place that no longer exists.
     */
    public function destroy(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('delete', $store);

        if (Store::count() <= 1) {
            return back()->withErrors([
                'store' => 'This is the only store. The app needs at least one to sell anything.',
            ]);
        }

        if ($store->orders()->exists()) {
            return back()->withErrors([
                'store' => "“{$store->name}” has sales history and cannot be deleted. Its orders must keep pointing at a real store.",
            ]);
        }

        // A cashier without a store cannot open the POS at all, so make the
        // operator reassign people deliberately rather than silently orphan
        // them via the nullOnDelete foreign key.
        $staff = $store->users()->count();

        if ($staff > 0) {
            return back()->withErrors([
                'store' => "Move the {$staff} staff member(s) assigned to “{$store->name}” to another store first.",
            ]);
        }

        $name = $store->name;
        $store->delete();

        return back()->with('success', "“{$name}” was deleted.");
    }

    public function storeRegister(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('update', $store);

        $store->registers()->create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]));

        return back()->with('success', 'Register added.');
    }

    public function updateRegister(Request $request, Store $store, Register $register): RedirectResponse
    {
        $this->authorize('update', $store);

        abort_unless($register->store_id === $store->id, 404);

        $register->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'store_id' => ['sometimes', Rule::in([$store->id])],
        ]));

        return back()->with('success', 'Register updated.');
    }
}
