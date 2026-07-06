<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Customer;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerReportController extends Controller
{
    /**
     * Customers Report
     * GET /api/reports/customers
     *
     * Params:
     *  - date_from   (Y-m-d)  optional
     *  - date_to     (Y-m-d)  optional
     *  - per_page    integer  (default: 10)
     *  - page        integer  (default: 1)
     */
    public function customers(Request $request)
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

            // ─── Cohort calculation: Get all time first visit per customer ───
            $firstBills = Bill::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->select('customer_id', DB::raw('MIN(created_at) as first_visit_date'))
                ->groupBy('customer_id')
                ->pluck('first_visit_date', 'customer_id')
                ->toArray();

            // ─── Query for Bills within the filtered date range ────────
            $periodBillsQuery = Bill::withoutGlobalScopes()
                ->where('bills.company_id', $companyId);

            if ($request->filled('date_from')) {
                $periodBillsQuery->whereDate('bills.created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $periodBillsQuery->whereDate('bills.created_at', '<=', $request->date_to);
            }

            // ─── 1. Summary Metrics & Chart data ───
            $periodBills = (clone $periodBillsQuery)->get();

            $summary = $this->getSummaryData($periodBills, $firstBills, $request);
            $chart   = $this->getMonthlyChartData($periodBills, $firstBills);

            // ─── 2. Paginated Top Customers by Spend ───────────────────
            $topCustomers = $this->getTopCustomers($periodBillsQuery, $perPage, $page);

            return response()->json([
                'message' => 'Customers report fetched successfully',
                'data'    => [
                    'summary'                => $summary,
                    'new_vs_returning_chart' => $chart,
                    'top_customers'          => $topCustomers,
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
     * Compute Summary metrics based on bills in the range
     */
    private function getSummaryData($periodBills, array $firstBills, Request $request): array
    {
        $uniqueCustomerIds = $periodBills->pluck('customer_id')->unique()->filter()->values()->toArray();
        $totalCustomers    = count($uniqueCustomerIds);
        $newCustomers      = 0;

        $dateFrom = $request->filled('date_from') ? $request->date_from : null;
        $dateTo   = $request->filled('date_to') ? $request->date_to : null;

        foreach ($uniqueCustomerIds as $cId) {
            $firstVisit = $firstBills[$cId] ?? null;
            if (!$firstVisit) {
                continue;
            }

            $firstVisitDate = date('Y-m-d', strtotime($firstVisit));

            // Check if first visit is inside the filtered range
            $isNew = true;
            if ($dateFrom && $firstVisitDate < $dateFrom) {
                $isNew = false;
            }
            if ($dateTo && $firstVisitDate > $dateTo) {
                $isNew = false;
            }

            if ($isNew) {
                $newCustomers++;
            }
        }

        $returningCustomers = $totalCustomers - $newCustomers;
        $retentionRate      = $totalCustomers > 0
            ? round(($returningCustomers / $totalCustomers) * 100, 2)
            : 0;

        return [
            'total_customers'     => $totalCustomers,
            'new_customers'       => $newCustomers,
            'returning_customers' => $returningCustomers,
            'retention_rate'      => $retentionRate,
        ];
    }

    /**
     * Assemble monthly stacked chart of New vs Returning customers
     */
    private function getMonthlyChartData($periodBills, array $firstBills): array
    {
        $monthlyData = [];

        foreach ($periodBills as $bill) {
            if (!$bill->customer_id) {
                continue;
            }
            $month = $bill->created_at->format('Y-m');
            $cId   = $bill->customer_id;

            if (!isset($monthlyData[$month])) {
                $monthlyData[$month] = [
                    'new'       => [],
                    'returning' => [],
                ];
            }

            $firstVisit = $firstBills[$cId] ?? null;
            $firstMonth = $firstVisit ? date('Y-m', strtotime($firstVisit)) : null;

            if ($firstMonth === $month) {
                $monthlyData[$month]['new'][$cId] = true;
            } else {
                $monthlyData[$month]['returning'][$cId] = true;
            }
        }

        ksort($monthlyData);

        $chart = [];
        foreach ($monthlyData as $month => $data) {
            $dateObj = \DateTime::createFromFormat('Y-m', $month);
            $label   = $dateObj ? $dateObj->format('M Y') : $month;

            $chart[] = [
                'month'     => $month,
                'label'     => $label,
                'new'       => count($data['new']),
                'returning' => count($data['returning']),
            ];
        }

        return $chart;
    }

    /**
     * Top customers table query & pagination
     */
    private function getTopCustomers($query, int $perPage, int $page): array
    {
        $baseQuery = (clone $query)
            ->join('customers', 'bills.customer_id', '=', 'customers.id')
            ->select(
                'bills.customer_id',
                'customers.name',
                'customers.phone',
                DB::raw('COUNT(bills.id) as visits'),
                DB::raw('SUM(bills.total) as total_spent'),
                DB::raw('MAX(bills.created_at) as last_visit')
            )
            ->groupBy('bills.customer_id', 'customers.name', 'customers.phone')
            ->orderByRaw('SUM(bills.total) DESC');

        $totalResult = DB::table(DB::raw("({$baseQuery->toSql()}) as sub"))
            ->mergeBindings($baseQuery->getQuery())
            ->selectRaw('COUNT(*) as cnt')
            ->first();

        $total    = $totalResult ? (int) $totalResult->cnt : 0;
        $offset   = ($page - 1) * $perPage;
        $rows     = $baseQuery->offset($offset)->limit($perPage)->get();
        $lastPage = (int) ceil(max($total, 1) / $perPage);

        $mappedRows = $rows->map(fn($r) => [
            'name'        => $r->name,
            'phone'       => $r->phone,
            'visits'      => (int) $r->visits,
            'total_spent' => (float) $r->total_spent,
            'avg_spend'   => $r->visits > 0 ? round((float) $r->total_spent / (int) $r->visits, 2) : 0,
            'last_visit'  => $r->last_visit ? date('d-M-Y', strtotime($r->last_visit)) : null,
        ])->toArray();

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
