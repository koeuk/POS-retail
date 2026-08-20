<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\SalesReporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Store-wide sales history.
 *
 * Distinct from the receipt drawer inside /pos, which reads that one device's
 * IndexedDB and only knows about sales rung up on it. This reads the server,
 * so a manager can find any sale from any till — including one still sitting
 * unsynced on a tablet, which simply has not arrived here yet.
 */
class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->only('search', 'status', 'method', 'from', 'to');

        $orders = $this->scoped($user)
            ->with([
                'cashier:id,name',
                'store:id,name',
                'register:id,name',
                'customer:id,name',
                'payments:id,order_id,method,amount',
            ])
            ->withCount('items')
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('order_no', 'like', "%{$search}%")
                        ->orWhereHas('cashier', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(
                $filters['method'] ?? null,
                fn ($q, $method) => $q->whereHas('payments', fn ($p) => $p->where('method', $method))
            )
            // Filter on the business day too — the day the sale happened, not
            // the day the row reached the server.
            ->when(
                $filters['from'] ?? null,
                fn ($q, $from) => $q->whereRaw(SalesReporter::businessDay().' >= ?', [$from])
            )
            ->when(
                $filters['to'] ?? null,
                fn ($q, $to) => $q->whereRaw(SalesReporter::businessDay().' <= ?', [$to])
            )
            ->orderByRaw(SalesReporter::businessMoment().' DESC')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => collect(OrderStatus::cases())
                ->map(fn (OrderStatus $s) => ['value' => $s->value, 'label' => $s->label()]),
            'methods' => collect(PaymentMethod::cases())
                ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()]),
            'stores' => $user->isAdmin() ? Store::orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        // A manager must not be able to read another store's takings by
        // guessing an id — scope the lookup rather than the response.
        $this->scoped($request->user())->whereKey($order->id)->firstOrFail();

        $order->load([
            'items',
            'payments',
            'cashier:id,name',
            'store:id,name,address,phone',
            'register:id,name',
            'customer:id,name,phone,email',
        ]);

        return Inertia::render('Orders/Show', [
            'order' => $order,
            // Receipt header/footer come from settings so a reprint from here
            // matches what the till printed at the counter.
            'settings' => [
                'receipt_header' => Setting::get('receipt_header', config('app.name')),
                'receipt_footer' => Setting::get('receipt_footer'),
                'currency_symbol' => Setting::get('currency_symbol', '$'),
            ],
        ]);
    }

    /** Admins see every store; everyone else is pinned to their own. */
    private function scoped(User $user): Builder
    {
        return Order::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('store_id', $user->store_id));
    }
}
