<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncOrdersRequest;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Register;
use App\Models\Setting;
use App\Models\Store;
use App\Services\OrderSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON endpoints for the /pos screen.
 *
 * These are ordinary web.php routes — there is no routes/api.php and no
 * Sanctum in this build. They share the session cookie, CSRF protection and
 * middleware stack with every Inertia page; they simply return data instead
 * of an Inertia response, because an Inertia response cannot be queued in
 * Dexie and replayed hours later.
 */
class PosDataController extends Controller
{
    /**
     * Connectivity probe for the offline sync loop.
     *
     * navigator.onLine only reports link-layer state — it reports "online"
     * on wifi with no internet. The sync loop confirms with this before
     * trusting it. The response also refreshes the XSRF-TOKEN cookie, which
     * is how a 419 recovers after a long offline stretch.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'user_id' => $user->id,
            'store_id' => $user->store_id,
            'csrf_token' => csrf_token(),
        ]);
    }

    /**
     * Everything the POS screen needs to run offline, in one payload: the
     * catalogue, its stock, the registers to pick from and the receipt
     * settings. All of it goes straight into Dexie.
     */
    public function products(Request $request): JsonResponse
    {
        $storeId = $this->resolveStoreId($request);

        $products = Product::query()
            ->active()
            ->with('category:id,name')
            ->with(['stocks' => fn ($q) => $q->where('store_id', $storeId)])
            // A pack reads its shelf from the parent, so the parent's stock row
            // has to travel with it or the grid would show every case as zero.
            ->with(['parent' => fn ($q) => $q->with(['stocks' => fn ($s) => $s->where('store_id', $storeId)])])
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) {
                $stock = ($product->parent ?? $product)->stocks->first();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'parent_product_id' => $product->parent_product_id,
                    'units_per_pack' => $product->units_per_pack,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'category_id' => $product->category_id,
                    'category_name' => $product->category?->name,
                    'sell_price' => $product->sell_price,
                    'tax_rate' => $product->effectiveTaxRate(),
                    'unit' => $product->unit,
                    'image' => $product->image,
                    'track_stock' => $product->track_stock,

                    /*
                     * A hint for the cashier, never the source of truth — stock
                     * is only ever decided server-side at sync time. For a pack
                     * this is how many whole packs the loose count covers: 99
                     * cans is 4 cases, not 99.
                     */
                    'stock_qty' => intdiv($stock?->qty ?? 0, max(1, $product->units_per_pack)),
                ];
            });

        return response()->json([
            'store_id' => $storeId,
            'synced_at' => now()->toIso8601String(),
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'registers' => Register::where('store_id', $storeId)
                ->active()
                ->get(['id', 'name']),
            'settings' => [
                'receipt_header' => Setting::get('receipt_header', config('app.name')),
                'receipt_footer' => Setting::get('receipt_footer'),
                'currency_symbol' => Setting::get('currency_symbol', '$'),
            ],
        ]);
    }

    /**
     * Flush the offline queue. Always returns 200 with a per-order result —
     * a single malformed order must not strand the other 49 behind it.
     */
    public function sync(SyncOrdersRequest $request, OrderSyncService $sync): JsonResponse
    {
        $orders = $request->validated('orders');
        $storeId = $this->resolveStoreId($request);

        $orders = array_map(function (array $order) use ($storeId) {
            $order['store_id'] ??= $storeId;

            return $order;
        }, $orders);

        return response()->json([
            'synced_at' => now()->toIso8601String(),
            'results' => $sync->syncMany($orders, $request->user()),
        ]);
    }

    /** Lets the client confirm a flush it never saw the response to. */
    public function status(Request $request, string $clientUuid): JsonResponse
    {
        $order = Order::where('client_uuid', $clientUuid)->first();

        if (! $order) {
            return response()->json(['client_uuid' => $clientUuid, 'status' => 'pending'], 404);
        }

        return response()->json([
            'client_uuid' => $clientUuid,
            'status' => 'synced',
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'total' => $order->total,
            'synced_at' => $order->synced_at?->toIso8601String(),
        ]);
    }

    /**
     * A cashier is pinned to their own store. Admins and managers have no
     * store binding, so they may name one — falling back to the first store
     * rather than failing, since /pos must always be able to open.
     */
    private function resolveStoreId(Request $request): int
    {
        $user = $request->user();

        if ($user->store_id) {
            return $user->store_id;
        }

        $requested = $request->integer('store_id');

        if ($requested && Store::whereKey($requested)->exists()) {
            return $requested;
        }

        return (int) Store::query()->orderBy('id')->value('id');
    }
}
