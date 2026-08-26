<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Models\Order;
use App\Models\User;
use App\Services\SalesReporter;
use App\Support\Currency;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the owner took for themselves.
 *
 * These are not sales — they never touch the takings — but they are real
 * stock movements with a real cost, and a shop that does not know how much
 * it eats cannot tell why the shelf count and the bank balance disagree.
 * The figure shown is what the goods would have sold for.
 */
class ConsumptionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->only('search', 'from', 'to');

        $rows = $this->scoped($user)
            ->with(['cashier:id,name'])
            ->with(['items' => fn ($q) => $q->select('id', 'order_id', 'product_name', 'qty')])
            ->when($filters['search'] ?? null, fn (Builder $q, string $s) => $q
                ->whereHas('items', fn ($i) => $i->where('product_name', 'like', "%{$s}%")))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereRaw(SalesReporter::businessDay().' >= ?', [$from]))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereRaw(SalesReporter::businessDay().' <= ?', [$to]))
            ->orderByRaw(SalesReporter::businessMoment().' DESC')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        // This month, so the number means something at a glance.
        $month = SalesReporter::businessNow()->startOfMonth();
        $thisMonth = $this->scoped($user)
            ->whereRaw(SalesReporter::businessDay().' >= ?', [$month->toDateString()])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as value')
            ->first();

        return Inertia::render('Consumption/Index', [
            'rows' => $rows,
            'filters' => ['search' => $filters['search'] ?? '', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? ''],
            'summary' => [
                'month_count' => (int) ($thisMonth->n ?? 0),
                'month_value' => number_format((float) ($thisMonth->value ?? 0), 2, '.', ''),
                'month_label' => $month->format('F'),
            ],
            'currency' => Currency::current()->toArray(),
        ]);
    }

    private function scoped(User $user): Builder
    {
        return Order::query()
            ->where('sale_type', SaleType::Myself->value)
            ->where('status', OrderStatus::Completed)
            ->when(! $user->isAdmin(), fn ($q) => $q->where('store_id', $user->store_id));
    }
}
