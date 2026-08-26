<?php

namespace App\Http\Controllers;

use App\Enums\InventoryLogType;
use App\Enums\OrderStatus;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $filters = $request->only('search', 'category_id', 'status');

        $products = Product::query()
            /*
             * Base products only. A pack is a way of buying this product, not a
             * product of its own — it owns no stock, so listing it puts rows
             * reading "0 pcs" beside the real item and doubles the catalogue.
             * Its price appears on the parent's row as a range instead.
             */
            ->base()
            ->with('category:id,name')
            ->withSum('stocks as stock_qty', 'qty')
            ->withCount('packs')
            // Cheapest and dearest way to buy it, for the range in the list.
            ->withMin('packs as pack_min_price', 'sell_price')
            ->withMax('packs as pack_max_price', 'sell_price')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        // Scanning a case's barcode should find the product it
                        // belongs to, not nothing.
                        ->orWhereHas('packs', fn ($p) => $p
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%"));
                });
            })
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when(
                ($filters['status'] ?? null) !== null && $filters['status'] !== '',
                fn ($q) => $q->where('is_active', $filters['status'] === 'active')
            )
            ->latest('id')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return Inertia::render('Products/Index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('Products/Create', [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $data = $request->validated();
        $openingQty = (int) ($data['opening_qty'] ?? 0);
        $threshold = $data['low_stock_threshold'] ?? null;
        $packs = $data['packs'] ?? [];
        unset($data['opening_qty'], $data['low_stock_threshold'], $data['packs']);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product = DB::transaction(function () use ($data, $openingQty, $threshold, $packs, $request) {
            $product = Product::create($data);

            $this->syncPacks($product, $packs);

            // A pack draws stock from its parent, so it must not own rows of
            // its own — two shelves for one physical crate is the exact
            // disagreement this feature exists to avoid.
            if ($product->isPack()) {
                return $product;
            }

            // Seed a stock row per store so the POS product feed always has
            // one to read, even when opening quantity is zero.
            foreach (Store::pluck('id') as $storeId) {
                Stock::create([
                    'product_id' => $product->id,
                    'store_id' => $storeId,
                    'qty' => $openingQty,
                    'low_stock_threshold' => $threshold,
                ]);

                if ($openingQty > 0) {
                    InventoryLog::create([
                        'product_id' => $product->id,
                        'store_id' => $storeId,
                        'type' => InventoryLogType::Restock,
                        'qty_change' => $openingQty,
                        'reference_type' => Product::class,
                        'reference_id' => $product->id,
                        'note' => 'Opening stock',
                        'created_by' => $request->user()->id,
                    ]);
                }
            }

            return $product;
        });

        return to_route('products.index')
            ->with('success', "“{$product->name}” was created.");
    }

    /**
     * Read-only view. Everything about one product in one place: what it is,
     * where the stock sits, how it has moved and how it has sold. Editing
     * stays on its own screen so a glance can never become an accident.
     */
    public function show(Product $product): Response
    {
        $this->authorize('view', $product);

        return Inertia::render('Products/Show', [
            'product' => $product->load('category:id,name'),
            // Every way this can be bought. The list page shows only the range,
            // so this is where the individual prices live.
            'packs' => $product->packs()
                ->orderBy('units_per_pack')
                ->get(['id', 'name', 'units_per_pack', 'sell_price', 'is_active']),
            'stocks' => $product->stocks()->with('store:id,name')->get(),
            'movements' => $product->inventoryLogs()
                ->with(['store:id,name', 'creator:id,name'])
                ->latest('id')
                ->limit(10)
                ->get(),
            // How it has actually sold, from the snapshotted line items.
            'sales' => OrderItem::query()
                ->where('order_items.product_id', $product->id)
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', OrderStatus::Completed)
                ->selectRaw('COALESCE(SUM(order_items.qty), 0) as qty, COALESCE(SUM(order_items.subtotal), 0) as revenue')
                ->first(),
        ]);
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        return Inertia::render('Products/Edit', [
            'product' => $product->load('category:id,name', 'parent:id,name'),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'packs' => $product->packs()->orderBy('units_per_pack')->get(['id', 'name', 'units_per_pack', 'sell_price', 'is_active']),
            'stocks' => $product->stocks()->with('store:id,name')->get(),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $data = $request->validated();
        $packs = $data['packs'] ?? [];
        unset($data['opening_qty'], $data['low_stock_threshold'], $data['packs']);

        if ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $data['image'] = $request->file('image')->store('products', 'public');
        } else {
            unset($data['image']);
        }

        DB::transaction(function () use ($product, $data, $packs, $request) {
            $product->update($data);

            // A pack has no packs of its own, so the list is only meaningful on
            // a base product and is ignored entirely on a pack.
            if (! $product->isPack() && $request->has('packs')) {
                $this->syncPacks($product, $packs);
            }
        });

        return to_route('products.index')
            ->with('success', "“{$product->name}” was updated.");
    }

    /**
     * Bring a base product's pack sizes in line with what was submitted.
     *
     * Rows carrying an id are updated, new rows are created, and anything the
     * form dropped is removed — except a pack that has already been sold,
     * which is deactivated instead so its order history keeps pointing at a
     * real row.
     *
     * @param  array<int, array<string, mixed>>  $packs
     */
    private function syncPacks(Product $product, array $packs): void
    {
        $keptIds = [];

        foreach ($packs as $pack) {
            $attributes = [
                'category_id' => $product->category_id,
                'parent_product_id' => $product->id,
                'name' => $pack['name'],
                'units_per_pack' => (int) $pack['units_per_pack'],
                'sell_price' => $pack['sell_price'],
                'barcode' => ($pack['barcode'] ?? null) ?: null,
                'unit' => $product->unit,
                'track_stock' => $product->track_stock,
                'is_active' => true,
            ];

            $existing = isset($pack['id'])
                ? $product->packs()->whereKey($pack['id'])->first()
                : null;

            if ($existing) {
                $existing->update($attributes);
                $keptIds[] = $existing->id;

                continue;
            }

            $keptIds[] = Product::create($attributes + [
                'sku' => $this->packSku($product, (int) $pack['units_per_pack']),
            ])->id;
        }

        foreach ($product->packs()->whereKeyNot($keptIds)->get() as $removed) {
            if ($removed->orderItems()->exists()) {
                $removed->update(['is_active' => false]);

                continue;
            }

            $removed->delete();
        }
    }

    /**
     * A pack's SKU is derived, not typed: the shopkeeper is entering a name
     * and a price, and inventing a unique code per size is exactly the chore
     * this form exists to remove.
     */
    private function packSku(Product $product, int $units): string
    {
        $base = mb_substr($product->sku, 0, 50).'-'.$units;
        $sku = $base;
        $suffix = 1;

        while (Product::where('sku', $sku)->exists()) {
            $sku = $base.'-'.$suffix++;
        }

        return $sku;
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        // Products that have been sold are restricted by a foreign key on
        // order_items. Deactivate rather than delete — the sales history
        // must keep pointing at a real row.
        // The foreign key would refuse this anyway; saying so plainly beats a
        // 500 from the database.
        if ($product->packs()->exists()) {
            return back()->withErrors([
                'product' => 'This product has pack sizes. Delete those first.',
            ]);
        }

        if ($product->orderItems()->exists()) {
            $product->update(['is_active' => false]);

            return back()->with('success', "“{$product->name}” has sales history, so it was deactivated instead of deleted.");
        }

        $name = $product->name;

        DB::transaction(function () use ($product) {
            $product->stocks()->delete();
            $product->inventoryLogs()->delete();
            $product->delete();
        });

        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        return back()->with('success', "“{$name}” was deleted.");
    }
}
