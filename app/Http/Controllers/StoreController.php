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
