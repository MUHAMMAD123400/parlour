<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Plan;
use App\Models\CompanySubscribePlan;
use App\Models\CompanySubscribePlanHistory;

class CompanySubscriptionController extends Controller
{
    public function subscribe(Request $request)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'plan_id' => 'required|exists:plans,id',
            'start_date' => 'required|date',
            'type' => 'required|in:monthly,quarterly,yearly',
        ]);

        // calculate expiry
        $start = now()->parse($request->start_date);

        $endDate = match ($request->type) {
            'monthly' => $start->copy()->addMonth(),
            'quarterly' => $start->copy()->addMonths(3),
            'yearly' => $start->copy()->addYear(),
        };

        // deactivate old subscription
        CompanySubscribePlan::where('company_id', $request->company_id)
            ->update(['is_active' => 0]);

        // create new subscription
        $subscription = CompanySubscribePlan::create([
            'company_id' => $request->company_id,
            'plan_id' => $request->plan_id,
            'start_date' => $start,
            'end_date' => $endDate,
            'type' => $request->type,
            'is_active' => 1,
        ]);

        // history
        CompanySubscribePlanHistory::create([
            'company_id' => $request->company_id,
            'plan_id' => $request->plan_id,
            'start_date' => $start,
            'end_date' => $endDate,
            'action' => 'subscribed',
        ]);

        return response()->json([
            'message' => 'Subscribed successfully',
            'data' => $subscription
        ]);
    }

    public function unsubscribe(Request $request)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
        ]);

        $subscription = CompanySubscribePlan::where('company_id', $request->company_id)
            ->where('is_active', 1)
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription'], 404);
        }

        $subscription->update(['is_active' => 0]);

        CompanySubscribePlanHistory::create([
            'company_id' => $request->company_id,
            'plan_id' => $subscription->plan_id,
            'start_date' => $subscription->start_date,
            'end_date' => now(),
            'action' => 'unsubscribed',
        ]);

        return response()->json([
            'message' => 'Unsubscribed successfully'
        ]);
    }

    public function active(Request $request)
    {
        $subscription = CompanySubscribePlan::with('plan')
            ->where('company_id', $request->company_id)
            ->where('is_active', 1)
            ->first();

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription'
            ]);
        }

        $subscription->days_left = now()->diffInDays($subscription->end_date, false);

        return response()->json([
            'data' => $subscription
        ]);
    }

    public function history(Request $request)
    {
        $perPage = $request->per_page ?? 10;

        $history = CompanySubscribePlanHistory::with('plan')
            ->where('company_id', $request->company_id)
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
}
