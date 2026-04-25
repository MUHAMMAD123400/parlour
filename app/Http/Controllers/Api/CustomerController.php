<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Customer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Exception;

class CustomerController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:customer.index')->only(['index']);
        $this->middleware('permission:customer.show')->only(['show']);
        $this->middleware('permission:customer.create')->only(['store']);
        $this->middleware('permission:customer.edit')->only(['update']);
        $this->middleware('permission:customer.delete')->only(['destroy']);
    }

    /**
     * Display a listing of customers
     * GET /api/customers
     */
    public function index(Request $request)
    {
        try {
            $per_page = $request->per_page ?? 10;
            $companyId = $this->resolveAuthenticatedCompanyId($request->user());

            $query = Customer::query();
            $query->where('company_id', $companyId);

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%')
                      ->orWhere('phone', 'like', '%' . $search . '%')
                      ->orWhere('email', 'like', '%' . $search . '%');
                });
            }

            // Filter by tags
            if ($request->filled('tags')) {
                $tags = $this->normalizeTags($request->input('tags'));
                if ($tags !== []) {
                    $query->where(function ($q) use ($tags) {
                        foreach ($tags as $tag) {
                            $q->orWhereJsonContains('tags', $tag);
                        }
                    });
                }
            }

            // Calculate statistics (before pagination to get all customers)
            $totalCustomers = (clone $query)->count();
            $newThisMonth = (clone $query)->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count();

            $billStatsQuery = Bill::query();
            $billStatsQuery->where('company_id', $companyId);

            // Total revenue from bills in scope
            $totalRevenue = (clone $billStatsQuery)->sum('total');

            // Average spend per visit (average of all bill totals)
            $totalBills = (clone $billStatsQuery)->count();
            $avgSpendPerVisit = $totalBills > 0 ? round($totalRevenue / $totalBills, 2) : 0;

            $customers = $query->orderBy('created_at', 'desc')
                ->with('bills')
                ->withSum('bills', 'total')
                ->paginate($per_page);

            // Add total_spent key to each customer
            $customers->getCollection()->transform(function ($customer) {
                $customer->total_spent = (float) ($customer->bills_sum_total ?? 0);
                // Remove the temporary withSum attribute
                unset($customer->bills_sum_total);
                return $customer;
            });

            // Prepare statistics
            $statistics = [
                'total_customers' => $totalCustomers,
                'new_this_month' => $newThisMonth,
                'total_revenue' => (float) $totalRevenue,
                'avg_spend_per_visit' => (float) $avgSpendPerVisit,
            ];

            return \Helper::paginatedResponse($customers, $statistics);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Store a newly created customer
     * POST /api/customers/store
     */
    public function store(Request $request)
    {
        try {
            $companyId = $this->resolveAuthenticatedCompanyId($request->user());
            $request->merge([
                'tags' => $this->normalizeTagsForRequest($request->input('tags')),
            ]);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'email' => [
                    'nullable',
                    'email',
                    'max:255',
                    Rule::unique('customers', 'email')->where(fn ($q) => $q->where('company_id', $companyId)),
                ],
                'address' => 'nullable|string',
                'date_of_birth' => 'nullable|date',
                'tags' => 'nullable|array',
                'tags.*' => 'string',
                'notes' => 'nullable|string',
            ]);

            $customer = Customer::create([
                'company_id' => $companyId,
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'tags' => $validated['tags'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'message' => 'Customer created successfully',
                'data' => $customer
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Display the specified customer with full statistics
     * GET /api/customers/{id}/show
     */
    public function show($id)
    {
        try {
            $customer = Customer::findOrFail($id);
            
            // Get all bills for statistics
            $bills = Bill::query()->where('customer_id', $id)->get();
            
            // Calculate statistics
            $totalVisits = $bills->count();
            $totalSpent = (float) $bills->sum('total');
            $avgPerVisit = $totalVisits > 0 ? round($totalSpent / $totalVisits, 2) : 0;
            $memberSince = $customer->created_at->format('d-M-Y');
            $lastVisit = $bills->max('created_at') 
                ? $bills->max('created_at')->format('d-M-Y') 
                : null;
            
            // Add statistics to customer object
            $customer->total_visits = $totalVisits;
            $customer->total_spent = $totalSpent;
            $customer->avg_per_visit = $avgPerVisit;
            $customer->member_since = $memberSince;
            $customer->last_visit = $lastVisit;
            
            return response()->json([
                'message' => 'Customer fetched successfully',
                'data' => $customer
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Customer not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Get customer visit history (bills/invoices)
     * GET /api/customers/{id}/visit-history
     */
    public function visitHistory(Request $request, $id)
    {
        try {
            $customer = Customer::findOrFail($id);
            $per_page = $request->per_page ?? 10;
            
            $query = Bill::query()->where('customer_id', $id)
                ->with(['items.service', 'items.category', 'user'])
                ->orderBy('created_at', 'desc');
            
            // Filter by date range
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
            
            $bills = $query->paginate($per_page);
            
            // Transform bills to include service names
            $bills->getCollection()->transform(function ($bill) {
                $bill->services = $bill->items->map(function ($item) {
                    return $item->item_name;
                })->toArray();
                return $bill;
            });
            
            return \Helper::paginatedResponse($bills);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Customer not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Get customer spending analysis
     * GET /api/customers/{id}/spending-analysis
     */
    public function spendingAnalysis($id)
    {
        try {
            $customer = Customer::findOrFail($id);
            
            // Get all bills
            $bills = Bill::query()->where('customer_id', $id)
                ->with(['items.service', 'items.category'])
                ->orderBy('created_at', 'asc')
                ->get();
            
            // Monthly Spending (Last 6 Months)
            $monthlySpending = $this->getMonthlySpending($bills, 6);
            
            // Most Availed Services
            $mostAvailedServices = $this->getMostAvailedServices($bills);
            
            // Spending Trend (Last 6 Months)
            $spendingTrend = $this->getSpendingTrend($bills, 6);
            
            return response()->json([
                'message' => 'Spending analysis fetched successfully',
                'data' => [
                    'monthly_spending' => $monthlySpending,
                    'most_availed_services' => $mostAvailedServices,
                    'spending_trend' => $spendingTrend,
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Customer not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Get monthly spending for last N months
     */
    private function getMonthlySpending($bills, $months = 6)
    {
        $endDate = now();
        $startDate = now()->subMonths($months - 1)->startOfMonth();
        
        $monthlyData = [];
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();
            $monthKey = $monthStart->format('M');
            
            $monthTotal = $bills->filter(function ($bill) use ($monthStart, $monthEnd) {
                return $bill->created_at >= $monthStart && $bill->created_at <= $monthEnd;
            })->sum('total');
            
            $monthlyData[] = [
                'month' => $monthKey,
                'amount' => (float) $monthTotal,
            ];
        }
        
        return $monthlyData;
    }

    /**
     * Get most availed services with percentages
     */
    private function getMostAvailedServices($bills)
    {
        $serviceCounts = [];
        $totalItems = 0;
        
        foreach ($bills as $bill) {
            foreach ($bill->items as $item) {
                $category = $item->category->category_name ?? 'Other';
                if (!isset($serviceCounts[$category])) {
                    $serviceCounts[$category] = 0;
                }
                $serviceCounts[$category] += $item->quantity;
                $totalItems += $item->quantity;
            }
        }
        
        $services = [];
        foreach ($serviceCounts as $category => $count) {
            $percentage = $totalItems > 0 ? round(($count / $totalItems) * 100, 1) : 0;
            $services[] = [
                'category' => $category,
                'count' => $count,
                'percentage' => $percentage,
            ];
        }
        
        // Sort by count descending
        usort($services, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        
        return $services;
    }

    /**
     * Get spending trend for last N months
     */
    private function getSpendingTrend($bills, $months = 6)
    {
        $endDate = now();
        $startDate = now()->subMonths($months - 1)->startOfMonth();
        
        $trendData = [];
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();
            $monthKey = $monthStart->format('M');
            
            $monthTotal = $bills->filter(function ($bill) use ($monthStart, $monthEnd) {
                return $bill->created_at >= $monthStart && $bill->created_at <= $monthEnd;
            })->sum('total');
            
            $trendData[] = [
                'month' => $monthKey,
                'amount' => (float) $monthTotal,
            ];
        }
        
        return $trendData;
    }

    /**
     * Update the specified customer
     * POST /api/customers/{id}/update
     */
    public function update(Request $request, $id)
    {
        try {
            $customer = Customer::findOrFail($id);
            $request->merge([
                'tags' => $this->normalizeTagsForRequest($request->input('tags')),
            ]);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'email' => [
                    'nullable',
                    'email',
                    'max:255',
                    Rule::unique('customers', 'email')
                        ->ignore($customer->id)
                        ->where(fn ($q) => $q->where('company_id', $customer->company_id)),
                ],
                'address' => 'nullable|string',
                'date_of_birth' => 'nullable|date',
                'tags' => 'nullable|array',
                'tags.*' => 'string',
                'notes' => 'nullable|string',
            ]);

            $customer->update($validated);

            return response()->json([
                'message' => 'Customer updated successfully',
                'data' => $customer->fresh()
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Customer not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Remove the specified customer
     * DELETE /api/customers/{id}/delete
     */
    public function destroy($id)
    {
        try {
            $customer = Customer::findOrFail($id);
            $customer->delete();

            return response()->json([
                'message' => 'Customer deleted successfully',
                'data' => $customer
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Customer not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Normalize tags for query filtering.
     *
     * @return array<int, string>
     */
    private function normalizeTags(mixed $rawTags): array
    {
        if ($rawTags === null || $rawTags === '') {
            return [];
        }

        if (is_array($rawTags)) {
            $values = $rawTags;
        } elseif (is_string($rawTags)) {
            $decoded = json_decode($rawTags, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $values = $decoded;
            } else {
                $values = array_map('trim', explode(',', $rawTags));
            }
        } else {
            $values = [(string) $rawTags];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($v) => is_scalar($v) ? trim((string) $v) : '',
            $values
        ))));
    }

    /**
     * Normalize tags payload before validation.
     *
     * @return array<int, string>|null
     */
    private function normalizeTagsForRequest(mixed $rawTags): ?array
    {
        if ($rawTags === null || $rawTags === '') {
            return null;
        }

        $tags = $this->normalizeTags($rawTags);

        return $tags === [] ? null : $tags;
    }
}
