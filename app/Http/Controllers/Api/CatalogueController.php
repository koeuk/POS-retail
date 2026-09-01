<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Read side of the catalogue. Same filter grammar as the web screens —
 * `filter[search]`, `filter[category_id]`, `filter[status]` — so anything
 * learned on one surface transfers to the other.
 */
class CatalogueController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $products = QueryBuilder::for(Product::class)
            ->base()
            ->with('category:id,name')
            ->with(['packs:id,parent_product_id,name,units_per_pack,sell_price,is_active'])
            ->withSum('stocks as stock_qty', 'qty')
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, string $search) {
                    $query->where(fn (Builder $q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%"));
                }),
                AllowedFilter::exact('category_id'),
                AllowedFilter::callback('status', fn (Builder $q, string $status) => $q->where('is_active', $status === 'active')),
            ])
            ->latest('id')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return response()->json($products);
    }

    public function product(Product $product): JsonResponse
    {
        return response()->json($product->load([
            'category:id,name',
            'packs:id,parent_product_id,name,units_per_pack,sell_price,is_active',
            'stocks:id,product_id,store_id,qty,low_stock_threshold',
        ]));
    }

    public function categories(): JsonResponse
    {
        return response()->json(
            Category::query()->withCount('products')->orderBy('name')->get()
        );
    }
}
