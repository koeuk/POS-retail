<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** The same figures the Reports screen shows, as plain JSON. */
class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $reporter = SalesReporter::for($request->user());

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $reporter->rangeTotals($from, $to),
            'by_day' => $reporter->salesByDay($from, $to),
            'by_product' => $reporter->salesByProduct($from, $to),
            'by_payment' => $reporter->paymentBreakdown($from, $to),
            'debts_outstanding' => $reporter->outstandingDebts(),
        ]);
    }

    /**
     * Defaults to the last 30 days, clamped to a year — the same guardrails
     * as the web screen, so a scripted caller cannot ask for a decade of
     * daily rows and stall the server.
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
