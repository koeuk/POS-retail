<?php

namespace App\Http\Controllers;

use App\Enums\InventoryLogType;
use App\Enums\OrderStatus;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\Store;
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
            ->with('category:id,name')
            ->withSum('stocks as stock_qty', 'qty')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when(
                ($filters['status'] ?? null) !== null && $filters['status'] !== '',
                fn ($q) => $q->where('is_active', $filters['status'] === 'active')
            )
            ->latest('id')
            ->paginate(15)
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
            // Tax is no longer per product; the form shows which rate applies.
            'defaultTaxRate' => (float) (Setting::get('default_tax_rate') ?? 0),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $data = $request->validated();
        $openingQty = (int) ($data['opening_qty'] ?? 0);
        $threshold = $data['low_stock_threshold'] ?? null;
        unset($data['opening_qty'], $data['low_stock_threshold']);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product = DB::transaction(function () use ($data, $openingQty, $threshold, $request) {
            $product = Product::create($data);

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
            'taxRate' => $product->effectiveTaxRate(),
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
            'product' => $product->load('category:id,name'),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'defaultTaxRate' => $product->effectiveTaxRate(),
            'stocks' => $product->stocks()->with('store:id,name')->get(),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $data = $request->validated();
        unset($data['opening_qty'], $data['low_stock_threshold']);

        if ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $data['image'] = $request->file('image')->store('products', 'public');
        } else {
            unset($data['image']);
        }

        $product->update($data);

        return to_route('products.index')
            ->with('success', "“{$product->name}” was updated.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        // Products that have been sold are restricted by a foreign key on
        // order_items. Deactivate rather than delete — the sales history
        // must keep pointing at a real row.
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
