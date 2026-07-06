<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentReportController extends Controller
{
    /**
     * Payments Report
     * GET /api/reports/payments
     *
     * Params:
     *  - date_from   (Y-m-d)  optional
     *  - date_to     (Y-m-d)  optional
     *  - per_page    integer  (default: 10)
     *  - page        integer  (default: 1)
     */
    public function payments(Request $request)
    {
        try {
            $companyId = $this->resolveReportCompanyId($request->user(), $request->input('company_id'));

            $request->validate([
                'company_id' => 'nullable|integer|exists:companies,id',
                'date_from'  => 'nullable|date_format:Y-m-d',
                'date_to'    => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
                'per_page'   => 'nullable|integer|min:1|max:100',
            ]);

            $perPage = (int) $request->input('per_page', 10);
            $page    = (int) $request->input('page', 1);

            // ─── Base query representing only the bills of this company ──────────
            $baseQuery = Bill::withoutGlobalScopes()
                ->where('company_id', $companyId);

            if ($request->filled('date_from')) {
                $baseQuery->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $baseQuery->whereDate('created_at', '<=', $request->date_to);
            }

            // ─── 1. Summary details grouped by payment method ────────────────────
            $summaryDetails = $this->getSummaryDetails(clone $baseQuery);

            // ─── 2. Distribution calculation ─────────────────────────────────────
            $distribution = $this->getDistributionData($summaryDetails);

            // ─── 3. Daily stacked chart ──────────────────────────────────────────
            $dailyChart = $this->getDailyChartData(clone $baseQuery);

            // ─── 4. Paginated Daily Breakdown Table ─────────────────────────────
            $breakdownTable = $this->getBreakdownTable(clone $baseQuery, $perPage, $page);

            return response()->json([
                'message' => 'Payments report fetched successfully',
                'data'    => [
                    'summary'         => $summaryDetails,
                    'distribution'    => $distribution,
                    'daily_chart'     => $dailyChart,
                    'breakdown_table' => $breakdownTable,
                ],
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * Group payments by cash, card, online and compute counts/amounts
     */
    private function getSummaryDetails($query): array
    {
        $rows = (clone $query)
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as amount'))
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $cashMethod   = $rows->get('cash');
        $cardMethod   = $rows->get('card');
        $onlineMethod = $rows->get('online');

        $cashAmt   = $cashMethod ? (float) $cashMethod->amount : 0.0;
        $cashCnt   = $cashMethod ? (int) $cashMethod->count : 0;
        $cardAmt   = $cardMethod ? (float) $cardMethod->amount : 0.0;
        $cardCnt   = $cardMethod ? (int) $cardMethod->count : 0;
        $onlineAmt = $onlineMethod ? (float) $onlineMethod->amount : 0.0;
        $onlineCnt = $onlineMethod ? (int) $onlineMethod->count : 0;

        $totalAmt = $cashAmt + $cardAmt + $onlineAmt;
        $totalCnt = $cashCnt + $cardCnt + $onlineCnt;

        return [
            'cash' => [
                'amount'       => $cashAmt,
                'transactions' => $cashCnt,
            ],
            'card' => [
                'amount'       => $cardAmt,
                'transactions' => $cardCnt,
            ],
            'online' => [
                'amount'       => $onlineAmt,
                'transactions' => $onlineCnt,
            ],
            'total' => [
                'amount'       => $totalAmt,
                'transactions' => $totalCnt,
            ],
        ];
    }

    /**
     * Compute percentages for payment methods
     */
    private function getDistributionData(array $summary): array
    {
        $totalAmt = $summary['total']['amount'];

        $methods = ['cash', 'card', 'online'];
        $dist    = [];

        foreach ($methods as $method) {
            $amt  = $summary[$method]['amount'];
            $pct  = $totalAmt > 0 ? round(($amt / $totalAmt) * 100, 2) : 0.0;
            $dist[] = [
                'method'     => $method,
                'amount'     => $amt,
                'percentage' => $pct,
            ];
        }

        return $dist;
    }

    /**
     * Compile daily payments breakdown for stacked Y-axis chart
     */
    private function getDailyChartData($query): array
    {
        $rows = (clone $query)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw("SUM(CASE WHEN payment_method = 'cash' THEN total ELSE 0 END) as cash"),
                DB::raw("SUM(CASE WHEN payment_method = 'card' THEN total ELSE 0 END) as card"),
                DB::raw("SUM(CASE WHEN payment_method = 'online' THEN total ELSE 0 END) as online")
            )
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at) ASC')
            ->get();

        return $rows->map(fn($r) => [
            'date'   => $r->date,
            'cash'   => (float) $r->cash,
            'card'   => (float) $r->card,
            'online' => (float) $r->online,
        ])->toArray();
    }

    /**
     * Return paginated breakdown table with totals footer
     */
    private function getBreakdownTable($query, int $perPage, int $page): array
    {
        // 1. Totals row
        $totalsRowDetails = (clone $query)
            ->select(
                DB::raw("SUM(CASE WHEN payment_method = 'cash' THEN total ELSE 0 END) as cash"),
                DB::raw("SUM(CASE WHEN payment_method = 'card' THEN total ELSE 0 END) as card"),
                DB::raw("SUM(CASE WHEN payment_method = 'online' THEN total ELSE 0 END) as online"),
                DB::raw('SUM(total) as total')
            )
            ->first();

        // 2. Base grouped daily values
        $dailyQuery = (clone $query)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw("SUM(CASE WHEN payment_method = 'cash' THEN total ELSE 0 END) as cash"),
                DB::raw("SUM(CASE WHEN payment_method = 'card' THEN total ELSE 0 END) as card"),
                DB::raw("SUM(CASE WHEN payment_method = 'online' THEN total ELSE 0 END) as online"),
                DB::raw('SUM(total) as total')
            )
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at) DESC');

        // Total count of unique dates
        $totalResult = DB::table(DB::raw("({$dailyQuery->toSql()}) as sub"))
            ->mergeBindings($dailyQuery->getQuery())
            ->selectRaw('COUNT(*) as cnt')
            ->first();

        $total    = $totalResult ? (int) $totalResult->cnt : 0;
        $offset   = ($page - 1) * $perPage;
        $rows     = $dailyQuery->offset($offset)->limit($perPage)->get();
        $lastPage = (int) ceil(max($total, 1) / $perPage);

        $mappedRows = $rows->map(fn($r) => [
            'date'   => date('d-M-Y', strtotime($r->date)),
            'cash'   => (float) $r->cash,
            'card'   => (float) $r->card,
            'online' => (float) $r->online,
            'total'  => (float) $r->total,
        ])->toArray();

        return [
            'data' => $mappedRows,
            'totals' => [
                'cash'   => (float) ($totalsRowDetails->cash ?? 0),
                'card'   => (float) ($totalsRowDetails->card ?? 0),
                'online' => (float) ($totalsRowDetails->online ?? 0),
                'total'  => (float) ($totalsRowDetails->total ?? 0),
            ],
            'pagination' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $total,
            ],
        ];
    }
}
