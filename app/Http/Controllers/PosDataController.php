<?php

namespace App\Http\Controllers;

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
}
