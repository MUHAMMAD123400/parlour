<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->per_page ?? 10;
        
        $plans = Plan::query()
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->when($request->staff_limit, function ($query, $staffLimit) {
                $query->where('staff_limit', $staffLimit);
            })
            ->when($request->customer_limit, function ($query, $customerLimit) {
                $query->where('customer_limit', $customerLimit);
            })
            ->latest()
            ->paginate($perPage);
        
        return \Helper::paginatedResponse($plans);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'required|in:0,1',

            'staff_limit' => 'required|in:5,10,25,50,100,unlimited',

            'customer_limit' => 'required|in:500,1000,2000,5000,10000,unlimited',

            'monthly' => 'required|numeric|min:0',
            'quarterly' => 'required|numeric|min:0',
            'yearly' => 'required|numeric|min:0',

            'description' => 'nullable|string',
            'features' => 'nullable|string',
        ]);

        $plan = Plan::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Plan created successfully.',
            'data' => $plan
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id)
    {
        $plan = Plan::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $plan
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $plan = Plan::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'required|in:0,1',

            'staff_limit' => 'required|in:5,10,25,50,100,unlimited',

            'customer_limit' => 'required|in:500,1000,2000,5000,10000,unlimited',

            'monthly' => 'required|numeric|min:0',
            'quarterly' => 'required|numeric|min:0',
            'yearly' => 'required|numeric|min:0',

            'description' => 'nullable|string',
            'features' => 'nullable|string',
        ]);

        $plan->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Plan updated successfully.',
            'data' => $plan
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id)
    {
        $plan = Plan::findOrFail($id);

        $plan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Plan deleted successfully.'
        ]);
    }
}
