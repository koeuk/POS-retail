<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\User;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/** Shelf truth, read-only. Movements still go through the web screen or sync. */
class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $stocks = QueryBuilder::for($this->scoped($request->user()))
            ->with(['product:id,name,sku,barcode,unit,case_size,is_active', 'store:id,name'])
            ->whereHas('product', fn (Builder $q) => $q->where('is_active', true))
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, string $search) {
                    $query->whereHas('product', fn (Builder $q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%"));
                }),
                AllowedFilter::exact('store_id'),
                AllowedFilter::callback('state', fn (Builder $q, string $state) => match ($state) {
                    'low' => $q->whereNotNull('low_stock_threshold')
                        ->whereColumn('qty', '<=', 'low_stock_threshold')
                        ->where('qty', '>=', 0),
                    'oversold' => $q->where('qty', '<', 0),
                    'out' => $q->where('qty', '=', 0),
                    default => $q,
                }),
            ])
            ->allowedSorts(...[AllowedSort::field('qty')])
            ->defaultSort('qty')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return response()->json($stocks);
    }

    private function scoped(User $user): Builder
    {
        return Stock::query()
            ->when(! $user->isAdmin(), fn (Builder $q) => $q->where('store_id', $user->store_id));
    }
}
