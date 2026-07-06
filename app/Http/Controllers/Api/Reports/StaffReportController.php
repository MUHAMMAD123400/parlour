<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffReportController extends Controller
{
    /**
     * Staff Report
     * GET /api/reports/staff
     *
     * Params:
     *  - date_from   (Y-m-d)  optional
     *  - date_to     (Y-m-d)  optional
     *  - per_page    integer  (default: 10)
     *  - page        integer  (default: 1)
     */
    public function staff(Request $request)
    {
        try {
            $companyId = $this->resolveAuthenticatedCompanyId($request->user());

            $request->validate([
                'date_from' => 'nullable|date_format:Y-m-d',
                'date_to'   => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
                'per_page'  => 'nullable|integer|min:1|max:100',
            ]);

            $perPage = (int) $request->input('per_page', 10);
            $page    = (int) $request->input('page', 1);

            // ─── Base query representing only the bills of this company ──────────
            $baseQuery = Bill::withoutGlobalScopes()
                ->where('bills.company_id', $companyId);

            if ($request->filled('date_from')) {
                $baseQuery->whereDate('bills.created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $baseQuery->whereDate('bills.created_at', '<=', $request->date_to);
            }

            // ─── 1. Summary stats (Active Staff Count, Top Performer, Total Revenue/Invoices)
            $summary = $this->getSummaryData(clone $baseQuery);

            // ─── 2. Revenue per Staff Member (Chart Data) ────────────────────────
            $chart = $this->getChartData(clone $baseQuery);

            // ─── 3. Paginated Performance Table ──────────────────────────────────
            $table = $this->getPerformanceTable(clone $baseQuery, $perPage, $page);

            return response()->json([
                'message' => 'Staff report fetched successfully',
                'data'    => [
                    'summary'             => $summary,
                    'staff_revenue_chart' => $chart,
                    'performance_table'   => $table,
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
     * Aggregate staff metrics for top widgets
     */
    private function getSummaryData($query): array
    {
        // Total revenue & total invoices
        $totals = (clone $query)->selectRaw('
            COUNT(*) as total_invoices,
            COALESCE(SUM(total), 0) as total_revenue
        ')->first();

        // Active staff count (unique user_ids in the period)
        $activeStaffCount = (clone $query)
            ->distinct()
            ->count('user_id');

        // Top Performer by revenue
        $topPerformer = (clone $query)
            ->join('users', 'bills.user_id', '=', 'users.id')
            ->select('users.name', DB::raw('SUM(bills.total) as revenue'))
            ->groupBy('bills.user_id', 'users.name')
            ->orderByRaw('SUM(bills.total) DESC')
            ->first();

        return [
            'active_staff'  => $activeStaffCount,
            'top_performer' => $topPerformer ? [
                'name'    => $topPerformer->name,
                'revenue' => (float) $topPerformer->revenue,
            ] : null,
            'total_revenue'  => (float) ($totals->total_revenue ?? 0.0),
            'total_invoices' => (int) ($totals->total_invoices ?? 0),
        ];
    }

    /**
     * Get revenue grouped by staff member for the bar chart
     */
    private function getChartData($query): array
    {
        $rows = (clone $query)
            ->join('users', 'bills.user_id', '=', 'users.id')
            ->select('users.name', DB::raw('SUM(bills.total) as revenue'))
            ->groupBy('bills.user_id', 'users.name')
            ->orderByRaw('SUM(bills.total) DESC')
            ->get();

        return $rows->map(fn($r) => [
            'name'    => $r->name,
            'revenue' => (float) $r->revenue,
        ])->toArray();
    }

    /**
     * Paginated staff performance table rows
     */
    private function getPerformanceTable($query, int $perPage, int $page): array
    {
        $baseGrouped = (clone $query)
            ->join('users', 'bills.user_id', '=', 'users.id')
            ->select(
                'bills.user_id',
                'users.name',
                DB::raw('COUNT(bills.id) as invoices_handled'),
                DB::raw('SUM(bills.total) as revenue')
            )
            ->groupBy('bills.user_id', 'users.name')
            ->orderByRaw('SUM(bills.total) DESC');

        $totalResult = DB::table(DB::raw("({$baseGrouped->toSql()}) as sub"))
            ->mergeBindings($baseGrouped->getQuery())
            ->selectRaw('COUNT(*) as cnt')
            ->first();

        $total  = $totalResult ? (int) $totalResult->cnt : 0;
        $offset = ($page - 1) * $perPage;

        $rows     = $baseGrouped->offset($offset)->limit($perPage)->get();
        $lastPage = (int) ceil(max($total, 1) / $perPage);

        $mappedRows = [];
        $rankIndex  = $offset + 1;

        foreach ($rows as $r) {
            $mappedRows[] = [
                'rank'             => $rankIndex++,
                'name'             => $r->name,
                'invoices_handled' => (int) $r->invoices_handled,
                'revenue'          => (float) $r->revenue,
                'avg_invoice'      => $r->invoices_handled > 0 ? round((float) $r->revenue / (int) $r->invoices_handled, 2) : 0.0,
            ];
        }

        return [
            'data'       => $mappedRows,
            'pagination' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $total,
            ],
        ];
    }
}
