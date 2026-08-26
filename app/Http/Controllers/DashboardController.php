<?php

namespace App\Http\Controllers;

use App\Services\SalesReporter;
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

        $todaySummary = $reporter->summaryFor($today);
        $yesterdaySummary = $reporter->summaryFor($yesterday);

        return Inertia::render('Dashboard', [
            'today' => $todaySummary,
            'yesterday' => $yesterdaySummary,

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
    }
}
