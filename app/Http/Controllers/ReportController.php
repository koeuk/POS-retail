<?php

namespace App\Http\Controllers;

use App\Services\SalesReporter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $reporter = SalesReporter::for($request->user());

        return Inertia::render('Reports/Index', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $reporter->rangeTotals($from, $to),
            'byDay' => $reporter->salesByDay($from, $to),
            'byProduct' => $reporter->salesByProduct($from, $to),
            'byPayment' => $reporter->paymentBreakdown($from, $to),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $reporter = SalesReporter::for($request->user());
        $rows = $reporter->salesByDay($from, $to);

        $filename = "sales-{$from->toDateString()}-to-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Date', 'Orders', 'Sales']);

            foreach ($rows as $row) {
                fputcsv($handle, [$row['day'], $row['orders'], $row['sales']]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Defaults to the last 30 days. The range is clamped so a hand-edited URL
     * cannot ask for a decade of daily rows and stall the page.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        // "Up to today" means the shop's today. On a server keeping UTC these
        // are different dates for part of every evening, and the difference is
        // a whole day's takings missing from the default view.
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
