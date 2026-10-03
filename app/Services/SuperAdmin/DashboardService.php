<?php

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\CompanyInvoice;
use App\Models\CompanySubscribePlan;
use App\Models\Module;
use App\Models\Plan;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Retrieve all 10 dashboard sections in a single structured response.
     */
    public function getDashboardData(): array
    {
        return [
            'stats' => $this->getAllStats(),
            'revenue_signups' => $this->getRevenueAndSignups(),
            'plan_distribution' => $this->getPlanDistribution(),
            'subscription_health' => $this->getSubscriptionHealth(),
            'module_adoption' => $this->getModuleAdoption(),
            'expiring_subscriptions' => $this->getExpiringSubscriptions(5),
            'recent_invoices' => $this->getRecentInvoices(6),
            'top_companies_by_revenue' => $this->getTopCompaniesByRevenue(5),
            'recent_signups' => $this->getRecentSignups(5),
        ];
    }

    /**
     * 1. ALL STATS
     * Calculates primary SaaS KPI metrics from active database records.
     */
    public function getAllStats(): array
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();
        $today = now()->toDateString();

        // Total companies registered (excluding soft-deleted)
        $totalCompanies = Company::count();

        // Active subscriptions currently running and valid today
        $activeSubscriptions = CompanySubscribePlan::where('is_active', 1)
            ->whereNull('deleted_at')
            ->whereDate('end_date', '>=', $today)
            ->count();

        // Monthly Recurring Revenue (MRR): calculated from currently active recurring subscriptions,
        // normalized to a 1-month equivalent based on billing cycle (yearly / 12, quarterly / 3, monthly)
        $mrr = (float) (DB::table('company_subscribe_plans')
            ->join('plans', 'company_subscribe_plans.plan_id', '=', 'plans.id')
            ->where('company_subscribe_plans.is_active', 1)
            ->whereNull('company_subscribe_plans.deleted_at')
            ->whereNull('plans.deleted_at')
            ->whereDate('company_subscribe_plans.end_date', '>=', $today)
            ->selectRaw("
                SUM(
                    CASE
                        WHEN company_subscribe_plans.type = 'yearly' THEN COALESCE(plans.yearly, 0) / 12
                        WHEN company_subscribe_plans.type = 'quarterly' THEN COALESCE(plans.quarterly, 0) / 3
                        ELSE COALESCE(plans.monthly, 0)
                    END
                ) as mrr
            ")
            ->value('mrr') ?? 0.0);
        $mrr = round($mrr, 2);

        // Annual Recurring Revenue (ARR): Standard formula MRR * 12
        $arr = round($mrr * 12, 2);

        // Outstanding Dues: Total amount of unpaid, pending, or overdue invoices
        $outstandingDues = (float) (CompanyInvoice::whereIn('payment_status', ['pending', 'overdue'])
            ->sum('total_amount') ?? 0.0);
        $outstandingDues = round($outstandingDues, 2);

        // Collected This Month: Successfully paid invoice total for the current calendar month
        $collectedThisMonth = (float) (CompanyInvoice::where('payment_status', 'paid')
            ->where(function ($q) use ($startOfMonth, $endOfMonth) {
                $q->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
                    ->orWhereBetween('created_at', [$startOfMonth, $endOfMonth]);
            })
            ->sum('total_amount') ?? 0.0);
        $collectedThisMonth = round($collectedThisMonth, 2);

        // New Signups: Companies created during the current calendar month
        $newSignups = Company::whereBetween('created_at', [$startOfMonth, $endOfMonth])->count();

        // Churn Rate: Subscriptions unsubscribed or expired this month / (active + churned this month)
        // Uses company_subscribe_plan_histories tracking actions 'unsubscribed' and 'expired'
        $churnedThisMonth = DB::table('company_subscribe_plan_histories')
            ->whereIn('action', ['unsubscribed', 'expired'])
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->count();

        $churnBase = $activeSubscriptions + $churnedThisMonth;
        $churnRate = $churnBase > 0 ? round(($churnedThisMonth / $churnBase) * 100, 2) : 0.0;

        // Average Revenue Per Company (ARPC): Total lifetime collected revenue divided by total companies
        $totalCollected = (float) (CompanyInvoice::where('payment_status', 'paid')->sum('total_amount') ?? 0.0);
        $avgRevenuePerCompany = $totalCompanies > 0 ? round($totalCollected / $totalCompanies, 2) : 0.0;

        // Awaiting Verification: Companies registered with inactive status (company_status = '0')
        // Note: The project does not currently have an explicit KYC/verification table; inactive status is used.
        $awaitingVerification = Company::where('company_status', '0')->count();

        return [
            'mrr' => $mrr,
            'arr' => $arr,
            'total_companies' => $totalCompanies,
            'active_subscriptions' => $activeSubscriptions,
            'outstanding_dues' => $outstandingDues,
            'collected_this_month' => $collectedThisMonth,
            'new_signups' => $newSignups,
            'churn_rate' => $churnRate,
            'average_revenue_per_company' => $avgRevenuePerCompany,
            'awaiting_verification' => $awaitingVerification,
        ];
    }

    /**
     * 2. REVENUE & SIGNUPS
     * Monthly aggregated data for dashboard chart (last 12 months).
     * Prevents month-overflow issues by anchoring to startOfMonth.
     */
    public function getRevenueAndSignups(): array
    {
        $startOfRange = now()->startOfMonth()->subMonths(11);
        $endOfRange = now()->endOfMonth();

        // Query 1: Monthly revenue from paid company invoices
        // Anchors to invoice `date` if within the historical range, or `created_at` as fallback
        $revenueByMonth = DB::table('company_invoices')
            ->where('payment_status', 'paid')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($startOfRange, $endOfRange) {
                $q->whereBetween('date', [$startOfRange->toDateString(), $endOfRange->toDateString()])
                    ->orWhereBetween('created_at', [$startOfRange, $endOfRange]);
            })
            ->selectRaw("
                DATE_FORMAT(
                    CASE
                        WHEN date BETWEEN '{$startOfRange->toDateString()}' AND '{$endOfRange->toDateString()}' THEN date
                        ELSE DATE(created_at)
                    END,
                    '%Y-%m'
                ) as ym,
                SUM(total_amount) as total
            ")
            ->groupBy('ym')
            ->pluck('total', 'ym')
            ->all();

        // Query 2: Monthly company registrations
        $signupsByMonth = DB::table('companies')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startOfRange, $endOfRange])
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as count")
            ->groupBy('ym')
            ->pluck('count', 'ym')
            ->all();

        $labels = [];
        $revenue = [];
        $signups = [];

        // Generate exactly 12 month buckets without day-of-month overflow
        for ($i = 11; $i >= 0; $i--) {
            $dt = now()->startOfMonth()->subMonths($i);
            $ym = $dt->format('Y-m');
            $labels[] = $dt->format('M y');
            $revenue[] = round((float) ($revenueByMonth[$ym] ?? 0.0), 2);
            $signups[] = (int) ($signupsByMonth[$ym] ?? 0);
        }

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'signups' => $signups,
        ];
    }

    /**
     * 3. PLAN DISTRIBUTION
     * Breakdown of companies and subscriptions across actual database plans.
     */
    public function getPlanDistribution(): array
    {
        $plans = Plan::select('id', 'name')->orderBy('id')->get();

        // Active subscriptions per plan
        $activeSubsByPlan = DB::table('company_subscribe_plans')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->select('plan_id', DB::raw('COUNT(*) as total'))
            ->groupBy('plan_id')
            ->pluck('total', 'plan_id')
            ->all();

        // Companies directly linked to plan_id
        $companiesByPlan = DB::table('companies')
            ->whereNull('deleted_at')
            ->whereNotNull('plan_id')
            ->select('plan_id', DB::raw('COUNT(*) as total'))
            ->groupBy('plan_id')
            ->pluck('total', 'plan_id')
            ->all();

        // Paid invoice revenue per plan
        $revenueByPlan = DB::table('company_invoices')
            ->where('payment_status', 'paid')
            ->whereNull('deleted_at')
            ->whereNotNull('plan_id')
            ->select('plan_id', DB::raw('SUM(total_amount) as total'))
            ->groupBy('plan_id')
            ->pluck('total', 'plan_id')
            ->all();

        $totalActiveSubs = array_sum($activeSubsByPlan);
        $totalPlanCompanies = array_sum($companiesByPlan);

        $distribution = [];
        foreach ($plans as $plan) {
            $subCount = (int) ($activeSubsByPlan[$plan->id] ?? 0);
            $compCount = (int) ($companiesByPlan[$plan->id] ?? 0);
            $planRevenue = round((float) ($revenueByPlan[$plan->id] ?? 0.0), 2);
            $count = $subCount > 0 ? $subCount : $compCount;
            $percentage = $totalActiveSubs > 0
                ? round(($subCount / $totalActiveSubs) * 100, 1)
                : ($totalPlanCompanies > 0 ? round(($compCount / $totalPlanCompanies) * 100, 1) : 0.0);

            $distribution[] = [
                'id' => $plan->id,
                'name' => $plan->name,
                'plan_name' => $plan->name,
                'companies_count' => $compCount,
                'subscription_count' => $subCount,
                'count' => $count,
                'total_revenue' => $planRevenue,
                'percentage' => $percentage,
            ];
        }

        return $distribution;
    }

    /**
     * 4. SUBSCRIPTION HEALTH
     * Categorizes subscriptions into Active, On Trial, Expiring Soon, and Expired.
     */
    public function getSubscriptionHealth(): array
    {
        $today = now()->toDateString();
        $expiringWindow = now()->addDays(7)->toDateString();

        // Expiring Soon: active subscriptions ending within the next 7 days
        $expiringSoonCount = DB::table('company_subscribe_plans')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->whereBetween('end_date', [$today, $expiringWindow])
            ->count();

        // Active: active subscriptions with end_date beyond the 7-day window
        $activeCount = DB::table('company_subscribe_plans')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->whereDate('end_date', '>', $expiringWindow)
            ->count();

        // Expired: inactive subscriptions or subscriptions past end_date
        $expiredCount = DB::table('company_subscribe_plans')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($today) {
                $q->where('is_active', 0)
                    ->orWhereDate('end_date', '<', $today);
            })
            ->count();

        // On Trial: The current project schema does not have a trial status or trial plan
        $onTrialCount = 0;

        $total = $activeCount + $expiringSoonCount + $expiredCount + $onTrialCount;
        $totalActive = $activeCount + $expiringSoonCount;

        return [
            'active' => $activeCount,
            'total_active' => $totalActive,
            'on_trial' => $onTrialCount,
            'expiring_soon' => $expiringSoonCount,
            'expired' => $expiredCount,
            'total' => $total,
            'breakdown' => [
                [
                    'status' => 'Active',
                    'count' => $activeCount,
                    'percentage' => $total > 0 ? round(($activeCount / $total) * 100, 1) : 0.0,
                ],
                [
                    'status' => 'On Trial',
                    'count' => $onTrialCount,
                    'percentage' => $total > 0 ? round(($onTrialCount / $total) * 100, 1) : 0.0,
                ],
                [
                    'status' => 'Expiring Soon',
                    'count' => $expiringSoonCount,
                    'percentage' => $total > 0 ? round(($expiringSoonCount / $total) * 100, 1) : 0.0,
                ],
                [
                    'status' => 'Expired',
                    'count' => $expiredCount,
                    'percentage' => $total > 0 ? round(($expiredCount / $total) * 100, 1) : 0.0,
                ],
            ],
        ];
    }

    /**
     * 5. MODULE ADOPTION
     * Calculates how many companies have each licensable module enabled.
     */
    public function getModuleAdoption(): array
    {
        $totalCompanies = Company::count();

        $modules = Module::whereNull('deleted_at')
            ->select('id', 'module_name', 'permission_module_key')
            ->orderBy('module_name')
            ->get();

        $adoptionCounts = DB::table('company_modules')
            ->join('companies', 'company_modules.company_id', '=', 'companies.id')
            ->whereNull('companies.deleted_at')
            ->where('company_modules.company_module_status', '1')
            ->whereNull('company_modules.deleted_at')
            ->select('company_modules.module_id', DB::raw('COUNT(DISTINCT company_modules.company_id) as count'))
            ->groupBy('company_modules.module_id')
            ->pluck('count', 'module_id')
            ->all();

        $adoption = [];
        foreach ($modules as $mod) {
            $compCount = (int) ($adoptionCounts[$mod->id] ?? 0);
            $percentage = $totalCompanies > 0 ? round(($compCount / $totalCompanies) * 100, 1) : 0.0;

            $adoption[] = [
                'id' => $mod->id,
                'module' => $mod->module_name,
                'module_name' => $mod->module_name,
                'key' => $mod->permission_module_key,
                'companies' => $compCount,
                'companies_count' => $compCount,
                'percentage' => $percentage,
            ];
        }

        return $adoption;
    }

    /**
     * 6. EXPIRING SUBSCRIPTIONS
     * Limited list of active subscriptions expiring soonest.
     */
    public function getExpiringSubscriptions(int $limit = 5): array
    {
        $today = now()->toDateString();

        $subscriptions = CompanySubscribePlan::with([
            'company:id,company_name,company_logo',
            'plan:id,name,monthly,quarterly,yearly',
        ])
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->whereDate('end_date', '>=', $today)
            ->orderBy('end_date', 'asc')
            ->limit($limit)
            ->get();

        // If no active subscriptions >= today, fallback to any active subscriptions ordered by end_date
        if ($subscriptions->isEmpty()) {
            $subscriptions = CompanySubscribePlan::with([
                'company:id,company_name,company_logo',
                'plan:id,name,monthly,quarterly,yearly',
            ])
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->orderBy('end_date', 'asc')
                ->limit($limit)
                ->get();
        }

        return $subscriptions->map(function ($sub) {
            $plan = $sub->plan;
            $amount = match ($sub->type) {
                'yearly' => (float) ($plan?->yearly ?? 0.0),
                'quarterly' => (float) ($plan?->quarterly ?? 0.0),
                default => (float) ($plan?->monthly ?? 0.0),
            };

            return [
                'id' => $sub->id,
                'company_id' => $sub->company_id,
                'company' => $sub->company?->company_name ?? '—',
                'company_name' => $sub->company?->company_name ?? '—',
                'company_logo' => $sub->company?->company_logo ? url($sub->company->company_logo) : null,
                'plan' => $plan?->name ?? '—',
                'plan_id' => $sub->plan_id,
                'subscription_amount' => $amount,
                'billing_cycle' => $sub->type,
                'start_date' => $sub->start_date?->format('Y-m-d'),
                'expiry_date' => $sub->end_date?->format('Y-m-d'),
                'expiry_date_formatted' => $sub->end_date?->format('d M Y'),
                'days_left' => $sub->end_date ? (int) now()->diffInDays($sub->end_date, false) : null,
                'payment_status' => $sub->payment_status,
                'is_active' => (bool) $sub->is_active,
            ];
        })->all();
    }

    /**
     * 7. RECENT INVOICES
     * Retrieves latest company invoices sorted newest first.
     */
    public function getRecentInvoices(int $limit = 6): array
    {
        $invoices = CompanyInvoice::with([
            'company:id,company_name,company_logo',
            'plan:id,name',
        ])
            ->latest('id')
            ->limit($limit)
            ->get();

        return $invoices->map(function ($inv) {
            return [
                'id' => $inv->id,
                'invoice_id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'company_id' => $inv->company_id,
                'company' => $inv->company?->company_name ?? '—',
                'company_name' => $inv->company?->company_name ?? '—',
                'company_logo' => $inv->company?->company_logo ? url($inv->company->company_logo) : null,
                'plan' => $inv->plan?->name ?? '—',
                'plan_id' => $inv->plan_id,
                'plan_name' => $inv->plan?->name ?? '—',
                'billing_cycle' => $inv->billing_cycle,
                'cycle_label' => $inv->cycle_label,
                'amount' => (float) $inv->amount,
                'discount' => (float) $inv->discount,
                'total_amount' => (float) $inv->total_amount,
                'payment_status' => $inv->payment_status,
                'status' => $inv->payment_status,
                'status_label' => $inv->status_label,
                'invoice_date' => $inv->date?->format('Y-m-d') ?? (string) $inv->date,
                'date' => $inv->date?->format('Y-m-d') ?? (string) $inv->date,
                'date_formatted' => $inv->date_formatted,
                'created_at' => $inv->created_at,
                'created_at_formatted' => $inv->created_at?->format('d-M-Y, g:i a'),
            ];
        })->all();
    }

    /**
     * 8. TOP COMPANIES BY REVENUE
     * Top companies ordered by lifetime collected revenue using database subquery aggregation.
     */
    public function getTopCompaniesByRevenue(int $limit = 5): array
    {
        $topCompanies = Company::query()
            ->leftJoinSub(
                DB::table('company_invoices')
                    ->select('company_id', DB::raw('SUM(total_amount) as lifetime_revenue'))
                    ->where('payment_status', 'paid')
                    ->whereNull('deleted_at')
                    ->groupBy('company_id'),
                'inv',
                'inv.company_id',
                '=',
                'companies.id'
            )
            ->select('companies.*', DB::raw('COALESCE(inv.lifetime_revenue, 0) as total_revenue'))
            ->withCount(['users', 'customers'])
            ->with(['subscription.plan', 'plan'])
            ->orderByDesc('total_revenue')
            ->orderBy('companies.id', 'asc')
            ->limit($limit)
            ->get();

        $rank = 1;

        return $topCompanies->map(function ($comp) use (&$rank) {
            $plan = $comp->subscription?->plan ?? $comp->plan;

            return [
                'rank' => $rank++,
                'company_id' => $comp->id,
                'company' => $comp->company_name,
                'company_name' => $comp->company_name,
                'company_logo' => $comp->company_logo ? url($comp->company_logo) : null,
                'plan' => $plan?->name ?? 'No Plan',
                'plan_id' => $plan?->id,
                'users_count' => (int) $comp->users_count,
                'customers_count' => (int) $comp->customers_count,
                'total_revenue' => (float) $comp->total_revenue,
                'lifetime_revenue' => (float) $comp->total_revenue,
            ];
        })->all();
    }

    /**
     * 9. RECENT SIGNUPS
     * Latest registered companies on the platform.
     */
    public function getRecentSignups(int $limit = 5): array
    {
        $signups = Company::with(['subscription.plan', 'plan'])
            ->latest('created_at')
            ->limit($limit)
            ->get();

        return $signups->map(function ($comp) {
            $plan = $comp->subscription?->plan ?? $comp->plan;

            $locationParts = array_filter([
                $comp->company_city,
                $comp->company_state,
                $comp->company_country,
            ]);

            return [
                'company_id' => $comp->id,
                'company_name' => $comp->company_name,
                'company_logo' => $comp->company_logo ? url($comp->company_logo) : null,
                'company_email' => $comp->company_email,
                'company_phone' => $comp->company_phone,
                'location' => [
                    'address' => $comp->company_address,
                    'city' => $comp->company_city,
                    'state' => $comp->company_state,
                    'zip' => $comp->company_zip,
                    'country' => $comp->company_country,
                ],
                'location_formatted' => ! empty($locationParts) ? implode(', ', $locationParts) : '—',
                'plan' => $plan?->name ?? 'No Plan',
                'plan_id' => $plan?->id,
                'company_status' => (string) $comp->company_status,
                'status_label' => $comp->company_status === '1' ? 'Active' : 'Inactive',
                'signup_date' => $comp->created_at?->format('Y-m-d H:i:s'),
                'signup_date_formatted' => $comp->created_at?->format('d M Y, g:i a'),
                'created_at' => $comp->created_at,
            ];
        })->all();
    }
}

