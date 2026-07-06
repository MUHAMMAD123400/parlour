<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\BillItem;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceReportController extends Controller
{
    /**
     * Services Report
     * GET /api/reports/services
     *
     * Query Parameters:
     *  - date_from   (Y-m-d)  optional
     *  - date_to     (Y-m-d)  optional
     *  - per_page    integer  (default: 10)
     *  - page        integer  (default: 1)
     */
    public function services(Request $request)
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

            // ─── Base query using BillItem Eloquent Model scoped to the company ──────────
            $baseQuery = BillItem::whereHas('bill', function ($q) use ($companyId, $request) {
                $q->where('company_id', $companyId);
                if ($request->filled('date_from')) {
                    $q->whereDate('created_at', '>=', $request->date_from);
                }
                if ($request->filled('date_to')) {
                    $q->whereDate('created_at', '<=', $request->date_to);
                }
            })
            ->where('item_type', 'service');

            // ─── 1. Summary Stats ───────────────────────────────────────────────
            $summary = $this->getSummary(clone $baseQuery);

            // ─── 2. Service Popularity List (sorted by booking count) ───────────
            $popularity = $this->getServicePopularity(clone $baseQuery);

            // ─── 3. Category Revenue (grouped by category) ──────────────────────
            $categoryRevenue = $this->getCategoryRevenue(clone $baseQuery);

            // ─── 4. Paginated All Services list ─────────────────────────────────
            $allServices = $this->getAllServicesPaginated(clone $baseQuery, $perPage, $page);

            return response()->json([
                'message' => 'Services report fetched successfully',
                'data'    => [
                    'summary'            => $summary,
                    'service_popularity' => $popularity,
                    'category_revenue'   => $categoryRevenue,
                    'all_services'       => $allServices,
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
     * Get summary metrics
     */
    private function getSummary($query): array
    {
        // 1. Total services performed
        $totalPerformed = (int) (clone $query)->sum('quantity');

        // 2. Most popular service by volume (quantity)
        $mostPopular = (clone $query)
            ->select('item_name', DB::raw('SUM(quantity) as bookings'))
            ->groupBy('item_name')
            ->orderByRaw('SUM(quantity) DESC')
            ->first();

        // 3. Highest revenue service
        $highestRevenue = (clone $query)
            ->select('item_name', DB::raw('SUM(total_price) as revenue'))
            ->groupBy('item_name')
            ->orderByRaw('SUM(total_price) DESC')
            ->first();

        return [
            'most_popular_service' => $mostPopular ? [
                'service_name' => $mostPopular->item_name,
                'bookings'     => (int) $mostPopular->bookings,
            ] : null,
            'highest_revenue_service' => $highestRevenue ? [
                'service_name' => $highestRevenue->item_name,
                'revenue'      => (float) $highestRevenue->revenue,
            ] : null,
            'total_services_performed' => $totalPerformed,
        ];
    }

    /**
     * Get Service Popularity list for bar chart
     */
    private function getServicePopularity($query): array
    {
        $rows = (clone $query)
            ->select('item_name as service_name', DB::raw('SUM(quantity) as bookings'))
            ->groupBy('item_name')
            ->orderByRaw('SUM(quantity) DESC')
            ->get();

        return $rows->map(fn($r) => [
            'service_name' => $r->service_name,
            'bookings'     => (int) $r->bookings,
        ])->toArray();
    }

    /**
     * Get Category Revenue list for pie/donut chart
     */
    private function getCategoryRevenue($query): array
    {
        $rows = (clone $query)
            ->join('categories', 'bill_items.category_id', '=', 'categories.id')
            ->select(
                'categories.category_name',
                'categories.color',
                DB::raw('SUM(bill_items.total_price) as revenue')
            )
            ->groupBy('categories.category_name', 'categories.color')
            ->orderByRaw('SUM(bill_items.total_price) DESC')
            ->get();

        return $rows->map(fn($r) => [
            'category_name' => $r->category_name,
            'revenue'       => (float) $r->revenue,
            'color'         => $r->color,
        ])->toArray();
    }

    /**
     * Get paginated table data
     */
    private function getAllServicesPaginated($query, int $perPage, int $page): array
    {
        $baseGrouped = (clone $query)
            ->leftJoin('categories', 'bill_items.category_id', '=', 'categories.id')
            ->select(
                'bill_items.service_id',
                'bill_items.item_name as service_name',
                DB::raw('COALESCE(categories.category_name, "Uncategorized") as category_name'),
                DB::raw('COALESCE(categories.color, "#808080") as category_color'),
                DB::raw('SUM(bill_items.quantity) as times_booked'),
                DB::raw('SUM(bill_items.total_price) as revenue')
            )
            ->groupBy('bill_items.service_id', 'bill_items.item_name', 'categories.category_name', 'categories.color')
            ->orderByRaw('SUM(bill_items.quantity) DESC');

        // Get total count of groups
        $total = DB::table(DB::raw("({$baseGrouped->toSql()}) as sub"))
            ->mergeBindings($baseGrouped->getQuery())
            ->count();

        $offset = ($page - 1) * $perPage;

        $rows = $baseGrouped->offset($offset)->limit($perPage)->get();
        $lastPage = (int) ceil(max($total, 1) / $perPage);

        $mappedRows = $rows->map(fn($r) => [
            'service_name' => $r->service_name,
            'category'     => [
                'name'  => $r->category_name,
                'color' => $r->category_color,
            ],
            'times_booked' => (int) $r->times_booked,
            'revenue'      => (float) $r->revenue,
            'avg_price'    => $r->times_booked > 0 ? round((float) $r->revenue / (int) $r->times_booked, 2) : 0,
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
