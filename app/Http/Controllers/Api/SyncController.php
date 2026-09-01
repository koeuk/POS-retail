<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncOrdersRequest;
use App\Services\OrderSyncService;
use Illuminate\Http\JsonResponse;

/**
 * The same offline-queue contract as /pos/data/orders/sync, reachable with a
 * token: identical request rules, identical per-order results, identical
 * idempotency on client_uuid. One contract, two doors.
 */
class SyncController extends Controller
{
    public function sync(SyncOrdersRequest $request, OrderSyncService $sync): JsonResponse
    {
        $orders = $request->validated('orders');
        $boundStoreId = $request->user()->store_id;

        $orders = array_map(function (array $order) use ($boundStoreId) {
            // A cashier's token is pinned to their store exactly as their
            // session is; the service enforces the same rule again.
            $order['store_id'] = $boundStoreId ?: ($order['store_id'] ?? null);

            return $order;
        }, $orders);

        return response()->json([
            'synced_at' => now()->toIso8601String(),
            'results' => $sync->syncMany($orders, $request->user()),
        ]);
    }
}
