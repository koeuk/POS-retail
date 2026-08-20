<?php

namespace App\Http\Controllers;

use App\Enums\InventoryLogType;
use App\Models\InventoryLog;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock management.
 *
 * Deliberately **not** raw CRUD on `stocks.qty`. Everything else in this build
 * treats `inventory_logs` as the ledger — a sale writes one, the dashboard's
 * oversold list is read against it, and the product screen states plainly that
 * stock moves only through sales and inventory movements. Letting someone type
 * a new number straight into the column would leave unexplained jumps that
 * nobody could reconcile a week later.
 *
 * So a quantity is never set directly; a **movement** is recorded and the
 * quantity follows. A stock-count correction still lets you land on any number
 * you like — it just works out the delta and writes down who changed it, when,
 * and why.
 */
class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->only('search', 'store_id', 'state');

        $stocks = $this->scoped($user)
            ->with(['product:id,name,sku,barcode,unit,is_active', 'store:id,name'])
            ->whereHas('product', fn (Builder $q) => $q->where('is_active', true))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->whereHas('product', function (Builder $q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->when(
                $filters['store_id'] ?? null,
                fn (Builder $q, $id) => $q->where('store_id', $id)
            )
            ->when(($filters['state'] ?? null) === 'low', fn (Builder $q) => $q
                ->whereNotNull('low_stock_threshold')
                ->whereColumn('qty', '<=', 'low_stock_threshold')
                ->where('qty', '>=', 0))
            ->when(($filters['state'] ?? null) === 'oversold', fn (Builder $q) => $q->where('qty', '<', 0))
            ->when(($filters['state'] ?? null) === 'out', fn (Builder $q) => $q->where('qty', '=', 0))
            ->orderBy('qty')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Inventory/Index', [
            'stocks' => $stocks,
            'filters' => $filters,
            'stores' => $user->isAdmin()
                ? Store::orderBy('name')->get(['id', 'name'])
                : Store::whereKey($user->store_id)->get(['id', 'name']),
            'movements' => $this->recentMovements($user),
            'summary' => $this->summary($user),
        ]);
    }

    /**
     * Products matching a search, for the "adjust any product" picker.
     *
     * JSON rather than an Inertia reload: the picker has to reach the whole
     * catalogue, not the twenty rows the table happens to be showing, and
     * re-rendering the page on every keystroke to do that would be absurd.
     */
    public function lookup(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q'));

        $rows = $this->scoped($request->user())
            ->with(['product:id,name,sku,barcode,unit', 'store:id,name'])
            ->whereHas('product', fn (Builder $q) => $q->where('is_active', true))
            ->when($search !== '', fn (Builder $query) => $query->whereHas(
                'product',
                fn (Builder $q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
            ))
            // Alphabetical by product: a picker should land where the eye
            // expects, unlike the table which leads with the lowest stock.
            ->join('products', 'products.id', '=', 'stocks.product_id')
            ->orderBy('products.name')
            ->orderBy('stocks.store_id')
            ->select('stocks.*')
            ->limit(20)
            ->get()
            ->map(fn (Stock $stock) => [
                'id' => $stock->id,
                'qty' => $stock->qty,
                'low_stock_threshold' => $stock->low_stock_threshold,
                'product' => [
                    'id' => $stock->product->id,
                    'name' => $stock->product->name,
                    'sku' => $stock->product->sku,
                    'barcode' => $stock->product->barcode,
                    'unit' => $stock->product->unit,
                ],
                'store' => ['id' => $stock->store->id, 'name' => $stock->store->name],
            ]);

        return response()->json(['results' => $rows]);
    }

    /**
     * Record a movement. The quantity is a consequence of it, never the input.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'stock_id' => ['required', 'integer', Rule::exists('stocks', 'id')],
            'mode' => ['required', Rule::in(['restock', 'remove', 'count', 'return'])],
            // For restock/remove/return this is a delta; for count it is the
            // shelf figure the counter actually saw.
            'quantity' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $stock = $this->scoped($user)->whereKey($data['stock_id'])->firstOrFail();

        [$change, $type] = match ($data['mode']) {
            'restock' => [$data['quantity'], InventoryLogType::Restock],
            'remove' => [-$data['quantity'], InventoryLogType::Adjustment],
            'return' => [$data['quantity'], InventoryLogType::Return],
            // A count is absolute: the delta is whatever reconciles the books
            // to the shelf, which may well be negative.
            'count' => [$data['quantity'] - $stock->qty, InventoryLogType::Adjustment],
        };

        if ($change === 0) {
            return back()->with('success', 'Nothing to record — the count already matches.');
        }

        DB::transaction(function () use ($stock, $change, $type, $data, $user) {
            // A single SQL statement, so two people counting at once cannot
            // read-modify-write over each other.
            $stock->increment('qty', $change);

            InventoryLog::create([
                'product_id' => $stock->product_id,
                'store_id' => $stock->store_id,
                'type' => $type,
                'qty_change' => $change,
                'reference_type' => Stock::class,
                'reference_id' => $stock->id,
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);
        });

        $stock->refresh();

        return back()->with(
            'success',
            sprintf(
                '%s: %s%d %s — now %d on hand.',
                $stock->product->name,
                $change > 0 ? '+' : '',
                $change,
                $stock->product->unit,
                $stock->qty,
            )
        );
    }

    /** The low-stock threshold is a setting on the row, not a movement. */
    public function updateThreshold(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'stock_id' => ['required', 'integer', Rule::exists('stocks', 'id')],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
        ]);

        $stock = $this->scoped($user)->whereKey($data['stock_id'])->firstOrFail();
        $stock->update(['low_stock_threshold' => $data['low_stock_threshold']]);

        return back()->with('success', 'Low-stock alert updated.');
    }

    private function recentMovements(User $user): Collection
    {
        return InventoryLog::query()
            ->with(['product:id,name,unit', 'store:id,name', 'creator:id,name'])
            ->when(! $user->isAdmin(), fn ($q) => $q->where('store_id', $user->store_id))
            ->latest('id')
            ->limit(15)
            ->get();
    }

    /** @return array{tracked: int, low: int, out: int, oversold: int} */
    private function summary(User $user): array
    {
        $base = fn () => $this->scoped($user)
            ->whereHas('product', fn (Builder $q) => $q->where('is_active', true));

        return [
            'tracked' => $base()->count(),
            'low' => $base()
                ->whereNotNull('low_stock_threshold')
                ->whereColumn('qty', '<=', 'low_stock_threshold')
                ->where('qty', '>=', 0)
                ->count(),
            'out' => $base()->where('qty', '=', 0)->count(),
            'oversold' => $base()->where('qty', '<', 0)->count(),
        ];
    }

    /** Admins see every store; everyone else is pinned to their own. */
    private function scoped(User $user): Builder
    {
        return Stock::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('store_id', $user->store_id));
    }
}
