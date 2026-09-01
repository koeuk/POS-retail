<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Order history, read-only. Same visibility rule as the web screen: admins
 * see every store, everyone else only their own.
 */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = QueryBuilder::for($this->scoped($request->user()))
            ->with(['cashier:id,name', 'store:id,name', 'customer:id,name', 'payments:id,order_id,method,amount'])
            ->withCount('items')
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, string $search) {
                    $query->where(fn (Builder $q) => $q
                        ->where('order_no', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")));
                }),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('sale_type'),
                AllowedFilter::callback('from', fn (Builder $q, string $from) => $q->businessDayFrom($from)),
                AllowedFilter::callback('to', fn (Builder $q, string $to) => $q->businessDayTo($to)),
            ])
            ->latestByBusinessMoment()
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return response()->json($orders);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->scoped($request->user())->whereKey($order->id)->firstOrFail();

        return response()->json(
            $order->load(['items', 'payments', 'customer:id,name,phone', 'cashier:id,name', 'store:id,name'])
                ->setAttribute('outstanding', $order->outstanding())
        );
    }

    private function scoped(User $user): Builder
    {
        return Order::query()
            ->when(! $user->isAdmin(), fn (Builder $q) => $q->where('store_id', $user->store_id));
    }
}
