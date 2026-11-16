<?php

namespace App\Http\Controllers;

use App\Services\SalesReporter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AnalyzeController extends Controller
{
    private const UNAVAILABLE = 'The analysis could not be loaded. Try again in a moment.';

    public function index(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $reporter = SalesReporter::for($request->user());
        $filters = ['from' => $from->toDateString(), 'to' => $to->toDateString()];

        try {
            return Inertia::render('Analyze/Index', [
                'filters' => $filters,
                'overview' => $reporter->profitabilityOverview($from, $to),
                'byDay' => $reporter->salesByDay($from, $to),
                'byHour' => $reporter->hourlySales($from, $to),
                'byDayOfWeek' => $reporter->dayOfWeekSales($from, $to),
                'byCategory' => $reporter->categorySales($from, $to),
                'byPayment' => $reporter->paymentBreakdown($from, $to),
                'customerInsights' => $reporter->customerInsights($from, $to),
                'productProfitability' => $reporter->productProfitability($from, $to),
            ]);
        } catch (QueryException $e) {
            report($e);
            $request->session()->flash('error', self::UNAVAILABLE);

            return Inertia::render('Analyze/Index', [
                'filters' => $filters,
                'overview' => [
                    'orders' => 0,
                    'items' => 0,
                    'revenue' => '0.00',
                    'cost' => '0.00',
                    'profit' => '0.00',
                    'margin_percent' => 0,
                    'avg_basket' => '0.00',
                ],
                'byDay' => [],
                'byHour' => [],
                'byDayOfWeek' => [],
                'byCategory' => [],
                'byPayment' => [],
                'customerInsights' => [
                    'total_orders' => 0,
                    'named_orders' => 0,
                    'walkin_orders' => 0,
                    'unique_customers' => 0,
                    'named_revenue' => '0.00',
                    'walkin_revenue' => '0.00',
                    'named_pct' => 0,
                ],
                'productProfitability' => [],
            ]);
        }
    }

    /**
     * Defaults to the last 30 days. The range is clamped to 366 days max.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $to = $request->date('to') ?? SalesReporter::businessNow();
        $from = $request->date('from') ?? $to->copy()->subDays(29);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }
}
