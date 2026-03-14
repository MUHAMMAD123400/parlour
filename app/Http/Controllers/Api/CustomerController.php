<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class CustomerController extends Controller
{
    public function __construct()
    {
        // $this->middleware('permission:customer.index')->only(['index']);
        // $this->middleware('permission:customer.show')->only(['show']);
        // $this->middleware('permission:customer.create')->only(['store']);
        // $this->middleware('permission:customer.edit')->only(['update']);
        // $this->middleware('permission:customer.delete')->only(['destroy']);
    }

    /**
     * Display a listing of customers
     * GET /api/customers
     */
    public function index(Request $request)
    {
        try {
            $per_page = $request->per_page ?? 10;
            
            $query = Customer::query();

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
                $tags = is_array($request->tags) ? $request->tags : [$request->tags];
                $query->whereJsonContains('tags', $tags);
            }

            $customers = $query->orderBy('created_at', 'desc')->paginate($per_page);

            return \Helper::paginatedResponse($customers);
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
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'email' => 'nullable|email|max:255|unique:customers,email',
                'address' => 'nullable|string',
                'date_of_birth' => 'nullable|date',
                'tags' => 'nullable|array',
                'tags.*' => 'string',
                'notes' => 'nullable|string',
            ]);

            $customer = Customer::create($validated);

            return response()->json([
                'message' => 'Customer created successfully',
                'data' => $customer
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Display the specified customer
     * GET /api/customers/{id}/show
     */
    public function show($id)
    {
        try {
            $customer = Customer::findOrFail($id);
            
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
     * Update the specified customer
     * POST /api/customers/{id}/update
     */
    public function update(Request $request, $id)
    {
        try {
            $customer = Customer::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'email' => 'nullable|email|max:255|unique:customers,email,' . $customer->id,
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
}
