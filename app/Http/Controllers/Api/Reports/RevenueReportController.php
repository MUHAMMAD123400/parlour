<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RevenueReportController extends Controller
{
    /**
     * Revenue Report
     * GET /api/reports/revenue
     *
     * Query Parameters:
     *  - date_from   (Y-m-d)  optional
     *  - date_to     (Y-m-d)  optional
     *  - chart_type  daily|weekly|monthly  (default: daily)
     *  - per_page    integer  (default: 10)
     *  - page        integer  (default: 1)
     */
    public function revenue(Request $request)
    {
        try {
            $companyId = $this->resolveReportCompanyId($request->user(), $request->input('company_id'));
            
            $request->validate([
                'company_id' => 'nullable|integer|exists:companies,id',
                'date_from'  => 'nullable|date_format:Y-m-d',
                'date_to'    => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
                'chart_type' => 'nullable|in:daily,weekly,monthly',
                'per_page'   => 'nullable|integer|min:1|max:100',
            ]);

            $chartType = $request->input('chart_type', 'daily');
            $perPage   = (int) $request->input('per_page', 10);
            $page      = (int) $request->input('page', 1);

            // ─── Base query builder (tenant-scoped + date filters) ──────────────
            $base = Bill::withoutGlobalScopes()
                ->where('company_id', $companyId);

            if ($request->filled('date_from')) {
                $base->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $base->whereDate('created_at', '<=', $request->date_to);
            }

            // ─── 1. Summary Stats ───────────────────────────────────────────────
            $summary = $this->getSummary(clone $base);

            // ─── 2. Chart Data ──────────────────────────────────────────────────
            $chart = $this->getChartData(clone $base, $chartType);

            // ─── 3. Daily Breakdown (paginated) ─────────────────────────────────
            $breakdown = $this->getDailyBreakdown(clone $base, $perPage, $page);

            return response()->json([
                'message' => 'Revenue report fetched successfully',
                'data'    => [
                    'summary'         => $summary,
                    'chart'           => $chart,
                    'daily_breakdown' => $breakdown,
                ],
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * Summary KPIs
     */
    private function getSummary($query): array
    {
        $agg = (clone $query)->selectRaw('
            COUNT(*)                  AS total_invoices,
            COALESCE(SUM(subtotal),0) AS gross_revenue,
            COALESCE(SUM(discount_amount),0) AS total_discounts,
            COALESCE(SUM(total),0)    AS total_revenue
        ')->first();

        // Highest revenue day
        $highestDay = (clone $query)
            ->selectRaw('DATE(created_at) as date, SUM(total) as revenue')
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('SUM(total) DESC')
            ->first();

        // Average: total revenue / number of distinct active days
        $activeDays = (clone $query)
            ->selectRaw('COUNT(DISTINCT DATE(created_at)) as days')
            ->value('days');

        $avgDaily = $activeDays > 0
            ? round($agg->total_revenue / $activeDays, 2)
            : 0;

        return [
            'total_revenue'    => (float) $agg->total_revenue,
            'gross_revenue'    => (float) $agg->gross_revenue,
            'total_discounts'  => (float) $agg->total_discounts,
            'total_invoices'   => (int)   $agg->total_invoices,
            'avg_daily_revenue'=> $avgDaily,
            'highest_day'      => $highestDay ? [
                'date'    => $highestDay->date,
                'revenue' => (float) $highestDay->revenue,
            ] : null,
        ];
    }

    /**
     * Chart data grouped by daily / weekly / monthly
     */
    private function getChartData($query, string $chartType): array
    {
        switch ($chartType) {
            case 'weekly':
                $labelExpr  = "DATE_FORMAT(created_at, '%x-W%v')";   // e.g. 2026-W12
                $groupExpr  = "YEARWEEK(created_at, 1)";
                break;

            case 'monthly':
                $labelExpr  = "DATE_FORMAT(created_at, '%Y-%m')";     // e.g. 2026-03
                $groupExpr  = "DATE_FORMAT(created_at, '%Y-%m')";
                break;

            default: // daily
                $labelExpr  = "DATE(created_at)";
                $groupExpr  = "DATE(created_at)";
                break;
        }

        $rows = (clone $query)
            ->selectRaw("
                {$labelExpr}        AS label,
                COUNT(*)            AS invoices,
                COALESCE(SUM(subtotal),0)         AS gross_revenue,
                COALESCE(SUM(discount_amount),0)  AS discounts,
                COALESCE(SUM(total),0)             AS revenue
            ")
            ->groupByRaw($groupExpr)
            ->orderByRaw($groupExpr)
            ->get();

        return $rows->map(fn($r) => [
            'label'        => $r->label,
            'invoices'     => (int)   $r->invoices,
            'gross_revenue'=> (float) $r->gross_revenue,
            'discounts'    => (float) $r->discounts,
            'revenue'      => (float) $r->revenue,
        ])->values()->toArray();
    }

    /**
     * Daily breakdown with pagination
     */
    private function getDailyBreakdown($query, int $perPage, int $page): array
    {
        // Totals row (for the footer)
        $totals = (clone $query)->selectRaw('
            COUNT(*)                          AS total_invoices,
            COALESCE(SUM(subtotal),0)         AS gross_revenue,
            COALESCE(SUM(discount_amount),0)  AS total_discounts,
            COALESCE(SUM(total),0)            AS net_revenue
        ')->first();

        // Paginated daily rows
        $dailyQuery = (clone $query)
            ->selectRaw('
                DATE(created_at)                  AS date,
                COUNT(*)                          AS invoices,
                COALESCE(SUM(subtotal),0)         AS gross_revenue,
                COALESCE(SUM(discount_amount),0)  AS discounts,
                COALESCE(SUM(total),0)            AS net_revenue
            ')
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at) DESC');

        $totalResult = DB::table(DB::raw("({$dailyQuery->toSql()}) as sub"))
            ->mergeBindings($dailyQuery->getQuery())
            ->selectRaw('COUNT(*) as cnt')
            ->first();

        $total = $totalResult ? (int) $totalResult->cnt : 0;

        // Manual pagination via offset/limit
        $offset = ($page - 1) * $perPage;
        $rows   = $dailyQuery->offset($offset)->limit($perPage)->get();

        $lastPage = (int) ceil(max($total, 1) / $perPage);

        return [
            'data' => $rows->map(fn($r) => [
                'date'         => $r->date,
                'invoices'     => (int)   $r->invoices,
                'gross_revenue'=> (float) $r->gross_revenue,
                'discounts'    => (float) $r->discounts,
                'net_revenue'  => (float) $r->net_revenue,
            ])->values()->toArray(),

            'totals' => [
                'invoices'      => (int)   $totals->total_invoices,
                'gross_revenue' => (float) $totals->gross_revenue,
                'discounts'     => (float) $totals->total_discounts,
                'net_revenue'   => (float) $totals->net_revenue,
            ],

            'pagination' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => (int) $total,
            ],
        ];
    }
}
