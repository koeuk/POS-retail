<?php

namespace App\Http\Controllers;

use App\Enums\InventoryLogType;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
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
    public function __construct()
    {
        $this->authorizeResource(Product::class, 'product');
    }

    public function index(Request $request): Response
    {
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
        return Inertia::render('Products/Create', [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
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

    public function edit(Product $product): Response
    {
        return Inertia::render('Products/Edit', [
            'product' => $product->load('category:id,name'),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'stocks' => $product->stocks()->with('store:id,name')->get(),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
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
