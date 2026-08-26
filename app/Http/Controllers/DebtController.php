<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SaleType;
use App\Models\Order;
use App\Models\User;
use App\Services\SalesReporter;
use App\Support\Currency;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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
        $filters = $request->only('search', 'state');

        $debts = $this->scoped($user)
            ->with(['customer:id,name,phone', 'cashier:id,name'])
            ->withCount('items')
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $q->where(function (Builder $w) use ($search) {
                    $w->where('order_no', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%"));
                });
            })
            // Default to what is still owed; "settled" shows the paid-off history.
            ->when(
                ($filters['state'] ?? 'open') === 'open',
                fn (Builder $q) => $q->whereColumn('paid_amount', '<', 'total'),
                fn (Builder $q) => $q->whereColumn('paid_amount', '>=', 'total'),
            )
            ->orderByRaw(SalesReporter::businessMoment().' DESC')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        $outstanding = $this->scoped($user)
            ->whereColumn('paid_amount', '<', 'total')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total - paid_amount), 0) as owed')
            ->first();

        return Inertia::render('Debts/Index', [
            'debts' => $debts,
            'filters' => ['search' => $filters['search'] ?? '', 'state' => $filters['state'] ?? 'open'],
            'summary' => [
                'open_count' => (int) ($outstanding->n ?? 0),
                'owed' => number_format((float) ($outstanding->owed ?? 0), 2, '.', ''),
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
    }

    private function scoped(User $user): Builder
    {
        return Order::query()
            ->where('sale_type', SaleType::Debt->value)
            ->where('status', OrderStatus::Completed)
            ->when(! $user->isAdmin(), fn ($q) => $q->where('store_id', $user->store_id));
    }
}
