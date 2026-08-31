<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SaleType;
use App\Models\Order;
use App\Models\User;
use App\Support\Currency;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Money owed to the shop.
 *
 * A debt sale records the full total as owed. Settling it writes an ordinary
 * payment row against the same order and bumps paid_amount, so the payment
 * breakdown in Reports picks it up on the day the cash actually arrived —
 * not the day the goods left. Partial payments are fine; a debt is "settled"
 * the moment paid_amount reaches total.
 */
class DebtController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = [
            'search' => (string) $request->input('filter.search', ''),
            'state' => (string) $request->input('filter.state', 'open'),
        ];

        $debts = QueryBuilder::for($this->scoped($user))
            ->with([
                'customer:id,name,phone',
                'cashier:id,name',
                // What they took and what they have paid so far, so the page
                // can show the whole story of a debt without a round trip.
                'items:id,order_id,product_name,qty,unit_price,subtotal',
                'payments:id,order_id,method,amount,reference_no,created_at',
            ])
            ->withCount('items')
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $q, string $search) {
                    $q->where(function (Builder $w) use ($search) {
                        $w->where('order_no', 'like', "%{$search}%")
                            ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%"));
                    });
                }),
                // Default to what is still owed; "settled" shows the paid-off history.
                AllowedFilter::callback('state', fn (Builder $q, string $state) => $state === 'open'
                    ? $q->whereColumn('paid_amount', '<', 'total')
                    : $q->whereColumn('paid_amount', '>=', 'total'))->default('open'),
            ])
            ->latestByBusinessMoment()
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        $open = $this->scoped($user)->whereColumn('paid_amount', '<', 'total');
        $openCount = (clone $open)->count();
        $owed = (float) $open->sum(DB::raw('total - paid_amount'));

        return Inertia::render('Debts/Index', [
            'debts' => $debts,
            'filters' => $filters,
            'summary' => [
                'open_count' => $openCount,
                'owed' => number_format($owed, 2, '.', ''),
            ],
            'methods' => collect(PaymentMethod::cases())
                ->reject(fn (PaymentMethod $m) => $m === PaymentMethod::Credit) // paying a debt on credit is circular
                ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()])
                ->values(),
            'currency' => Currency::current()->toArray(),
        ]);
    }

    /** Record money received against a debt. */
    public function settle(Request $request, Order $order): RedirectResponse
    {
        try {
            $this->scoped($request->user())->whereKey($order->id)->firstOrFail();

            $owed = (float) $order->total - (float) $order->paid_amount;

            $data = $request->validate([
                'amount' => ['required', 'numeric', 'min:0.01', "max:{$owed}"],
                'method' => ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::Card->value, PaymentMethod::Qr->value])],
                'reference_no' => ['nullable', 'string', 'max:255'],
            ], [
                'amount.max' => 'That is more than is owed.',
            ]);

            DB::transaction(function () use ($order, $data) {
                $order->payments()->create([
                    'method' => $data['method'],
                    'amount' => number_format((float) $data['amount'], 2, '.', ''),
                    'reference_no' => $data['reference_no'] ?? null,
                ]);

                // Recompute from the ledger rather than adding, so a double-submit
                // cannot drift paid_amount away from what the payments say.
                $order->update(['paid_amount' => $order->payments()->sum('amount')]);
            });

            $order->refresh();
            $left = (float) $order->outstanding();

            return back()->with(
                'success',
                $left > 0
                    ? "Payment recorded. {$order->customer?->name} still owes ".Currency::current()->format($left).'.'
                    : "Debt settled — {$order->customer?->name} is paid up.",
            );
        } catch (QueryException $e) {
            return $this->failed($e, 'The payment could not be recorded. The debt is unchanged — try again.');
        }
    }

    private function scoped(User $user): Builder
    {
        return Order::query()
            ->where('sale_type', SaleType::Debt->value)
            ->where('status', OrderStatus::Completed)
            ->when(! $user->isAdmin(), fn ($q) => $q->where('store_id', $user->store_id));
    }
}
