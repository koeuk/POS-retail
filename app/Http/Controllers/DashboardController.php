<?php

namespace App\Http\Controllers;

use App\Services\SalesReporter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $reporter = SalesReporter::for($user);

        // The shop's day, not the server's — see SalesReporter::businessDay().
        $today = SalesReporter::businessNow()->startOfDay();
        $yesterday = $today->copy()->subDay();

        try {
            return Inertia::render('Dashboard', [
                'today' => $reporter->summaryFor($today),
                'yesterday' => $reporter->summaryFor($yesterday),

                // Seven days including today, for the sparkline.
                'trend' => $reporter->salesByDay($today->copy()->subDays(6), $today),

                'lowStock' => $reporter->lowStock(),

                // The reconciliation list: stock driven negative by offline sales
                // that synced after the shelf was already empty.
                'oversold' => $reporter->oversold(),

                'recentOrders' => $reporter->recentOrders(),
                'offlineToday' => $reporter->offlineOrdersToday($today),
                'canSeeReports' => $user->role->canAccessAdmin(),
            ]);
        } catch (QueryException $e) {
            // The first screen after login must open. Empty figures and a
            // reason beat a 500 that hides the Point of Sale link too.
            report($e);
            $request->session()->flash('error', 'Today\'s figures could not be loaded. Try again in a moment.');

            return Inertia::render('Dashboard', [
                'today' => SalesReporter::emptyTotals(),
                'yesterday' => SalesReporter::emptyTotals(),
                'trend' => [],
                'lowStock' => [],
                'oversold' => [],
                'recentOrders' => [],
                'offlineToday' => 0,
                'canSeeReports' => $user->role->canAccessAdmin(),
            ]);
        }
    }
}
