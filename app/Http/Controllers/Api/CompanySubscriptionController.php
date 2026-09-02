<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Plan;
use App\Models\CompanySubscribePlan;
use App\Models\CompanySubscribePlanHistory;

class CompanySubscriptionController extends Controller
{
    /**
     * SuperAdmin — All subscriptions with filters + sort.
     * GET /api/super-admin/subscription
     *
     * Query Params:
     *  - payment_status : pending | paid | failed | refunded
     *  - type           : monthly | quarterly | yearly
     *  - plan_id        : integer
     *  - company_id     : integer
     *  - date_from      : date (start_date se filter)
     *  - date_to        : date (start_date tak filter)
     *  - sort_by        : asc | desc  (default: desc)
     *  - per_page       : integer     (default: 10)
     */
    public function index(Request $request)
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'error'   => 'forbidden',
            ], 403);
        }

        $request->validate([
            'payment_status' => 'nullable|in:pending,paid,failed,refunded',
            'type'           => 'nullable|in:monthly,quarterly,yearly',
            'plan_id'        => 'nullable|integer|exists:plans,id',
            'company_id'     => 'nullable|integer|exists:companies,id',
            'date_from'      => 'nullable|date',
            'date_to'        => 'nullable|date|after_or_equal:date_from',
            'sort_by'        => 'nullable|in:asc,desc',
            'per_page'       => 'nullable|integer|min:1|max:200',
        ]);

        $query = CompanySubscribePlan::with(['plan', 'company']);

        // ── Filters ──────────────────────────────────────────────────────
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('plan_id')) {
            $query->where('plan_id', $request->plan_id);
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('start_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('start_date', '<=', $request->date_to);
        }
        // ─────────────────────────────────────────────────────────────────

        // ── Sort — default DESC ───────────────────────────────────────────
        $sortDirection = strtolower($request->sort_by ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy('created_at', $sortDirection);
        // ─────────────────────────────────────────────────────────────────

        $perPage = $request->per_page ?? 10;
        $data    = $query->paginate($perPage);

        return \Helper::paginatedResponse($data);
    }

    public function subscribe(Request $request)
    {
        $request->validate([
            'company_id'     => 'required|exists:companies,id',
            'plan_id'        => 'required|exists:plans,id',
            'start_date'     => 'required|date',
            'type'           => 'required|in:monthly,quarterly,yearly',
            'payment_status' => 'nullable|in:pending,paid,failed,refunded',
            'transaction_id' => 'nullable|string|max:255',
            'notes'          => 'nullable|string',
        ]);

        // ── Authorization check ──────────────────────────────────────────
        $user = $request->user();
        if (! $user->isSuperAdmin()) {
            // Normal user sirf apni company ka subscription kar sakta hai
            if ((int) $request->company_id !== (int) $user->company_id) {
                return response()->json([
                    'message' => 'You are not allowed to manage subscriptions for another company.',
                    'error'   => 'forbidden',
                ], 403);
            }
        }
        // ─────────────────────────────────────────────────────────────────

        // calculate expiry
        $start = now()->parse($request->start_date);

        $endDate = match ($request->type) {
            'monthly'   => $start->copy()->addMonth(),
            'quarterly' => $start->copy()->addMonths(3),
            'yearly'    => $start->copy()->addYear(),
        };

        // deactivate old subscription
        CompanySubscribePlan::where('company_id', $request->company_id)
            ->update(['is_active' => 0]);

        // create new subscription
        $subscription = CompanySubscribePlan::create([
            'company_id'     => $request->company_id,
            'plan_id'        => $request->plan_id,
            'start_date'     => $start,
            'end_date'       => $endDate,
            'type'           => $request->type,
            'payment_status' => $request->payment_status ?? 'pending',
            'transaction_id' => $request->transaction_id ?? null,
            'notes'          => $request->notes ?? null,
            'is_active'      => 1,
        ]);

        // history
        CompanySubscribePlanHistory::create([
            'company_id' => $request->company_id,
            'plan_id'    => $request->plan_id,
            'start_date' => $start,
            'end_date'   => $endDate,
            'action'     => 'subscribed',
        ]);

        return response()->json([
            'message' => 'Subscribed successfully',
            'data'    => $subscription,
        ]);
    }

    public function unsubscribe(Request $request)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
        ]);

        // ── Authorization check ──────────────────────────────────────────
        $user = $request->user();
        if (! $user->isSuperAdmin()) {
            if ((int) $request->company_id !== (int) $user->company_id) {
                return response()->json([
                    'message' => 'You are not allowed to manage subscriptions for another company.',
                    'error'   => 'forbidden',
                ], 403);
            }
        }
        // ─────────────────────────────────────────────────────────────────

        $subscription = CompanySubscribePlan::where('company_id', $request->company_id)
            ->where('is_active', 1)
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription'], 404);
        }

        $subscription->update(['is_active' => 0]);

        CompanySubscribePlanHistory::create([
            'company_id' => $request->company_id,
            'plan_id'    => $subscription->plan_id,
            'start_date' => $subscription->start_date,
            'end_date'   => now(),
            'action'     => 'unsubscribed',
        ]);

        return response()->json([
            'message' => 'Unsubscribed successfully',
        ]);
    }

    public function active(Request $request, $company_id = null)
    {
        // SuperAdmin route se company_id aata hai, normal user apni company ka dekhta hai
        $companyId = $company_id ?? $request->company_id ?? $request->user()->company_id;

        $subscription = CompanySubscribePlan::with('plan')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->first();

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription',
            ]);
        }

        $subscription->days_left = now()->diffInDays($subscription->end_date, false);

        return response()->json([
            'data' => $subscription,
        ]);
    }

    public function history(Request $request, $company_id = null)
    {
        // SuperAdmin route se company_id aata hai, normal user apni company ka dekhta hai
        $companyId = $company_id ?? $request->company_id ?? $request->user()->company_id;

        $perPage = $request->per_page ?? 10;

        $history = CompanySubscribePlanHistory::with('plan')
            ->where('company_id', $companyId)
            ->latest()
            ->paginate($perPage);

        return \Helper::paginatedResponse($history);
    }

    public function plans(Request $request)
    {
        $perPage = $request->per_page ?? 10;

        $plans = Plan::latest()->paginate($perPage);

        return \Helper::paginatedResponse($plans);
    }

    /**
     * SuperAdmin — kisi subscription ka payment status update kare.
     * POST /api/super-admin/subscription/{id}/payment-status
     */
    public function updatePaymentStatus(Request $request, $id)
    {
        // Sirf superadmin access kar sakta hai
        if (! $request->user()->isSuperAdmin()) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'error'   => 'forbidden',
            ], 403);
        }

        $validated = $request->validate([
            'payment_status' => 'required|in:pending,paid,failed,refunded',
            'transaction_id' => 'nullable|string|max:255',
            'notes'          => 'nullable|string',
        ]);

        $subscription = CompanySubscribePlan::find($id);

        if (! $subscription) {
            return response()->json([
                'message' => 'Subscription not found.',
                'error'   => 'not_found',
            ], 404);
        }

        $subscription->update($validated);

        return response()->json([
            'message' => 'Payment status updated successfully.',
            'data'    => $subscription->fresh(),
        ]);
    }
}
