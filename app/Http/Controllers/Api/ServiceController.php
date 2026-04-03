<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\Service;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:service.index')->only(['index']);
        $this->middleware('permission:service.show')->only(['show']);
        $this->middleware('permission:service.create')->only(['store']);
        $this->middleware('permission:service.edit')->only(['update']);
        $this->middleware('permission:service.delete')->only(['destroy']);
    }

    /**
     * Display a listing of services
     * GET /api/services
     */
    public function index(Request $request)
    {
        try {
            $per_page = $request->per_page ?? 10;

            $query = Service::with('category');
            if ($cid = $this->optionalSuperAdminCompanyId($request)) {
                $query->where('company_id', $cid);
            }

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('service_name', 'like', '%' . $search . '%')
                      ->orWhere('description', 'like', '%' . $search . '%')
                      ->orWhereHas('category', function ($categoryQuery) use ($search) {
                          $categoryQuery->where('category_name', 'like', '%' . $search . '%');
                      });
                });
            }

            // Filter by category
            if ($request->filled('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            $services = $query->orderBy('created_at', 'desc')->paginate($per_page);

            return \Helper::paginatedResponse($services);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Store a newly created service
     * POST /api/services/store
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            $this->forbidGuestCompanyStaff($user);

            $companyRule = $user->isSuperAdmin()
                ? ['required', 'integer', 'exists:companies,id']
                : ['prohibited'];

            $companyId = $user->isSuperAdmin()
                ? (int) $request->input('company_id')
                : (int) $user->company_id;

            $validated = $request->validate([
                'company_id' => $companyRule,
                'service_name' => 'required|string|max:255',
                'category_id' => [
                    'required',
                    'integer',
                    Rule::exists('categories', 'id')->where('company_id', $companyId),
                ],
                'status' => 'nullable|in:1,0',
                'price' => 'required|numeric|min:0',
                'duration' => 'required|integer|min:1',
                'description' => 'nullable|string',
            ]);

            if (! isset($validated['status'])) {
                $validated['status'] = '1';
            }

            $service = Service::create([
                'company_id' => $companyId,
                'service_name' => $validated['service_name'],
                'category_id' => $validated['category_id'],
                'status' => $validated['status'],
                'price' => $validated['price'],
                'duration' => $validated['duration'],
                'description' => $validated['description'] ?? null,
            ]);

            return response()->json([
                'message' => 'Service created successfully',
                'data' => $service
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Display the specified service
     * GET /api/services/{id}/show
     */
    public function show($id)
    {
        try {
            $service = Service::findOrFail($id);
            
            return response()->json([
                'message' => 'Service fetched successfully',
                'data' => $service
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Service not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Update the specified service
     * POST /api/services/{id}/update
     */
    public function update(Request $request, $id)
    {
        try {
            $this->forbidGuestCompanyStaff($request->user());

            $service = Service::findOrFail($id);

            $validated = $request->validate([
                'company_id' => 'prohibited',
                'service_name' => 'required|string|max:255',
                'category_id' => [
                    'required',
                    'integer',
                    Rule::exists('categories', 'id')->where('company_id', $service->company_id),
                ],
                'status' => 'nullable|in:1,0',
                'price' => 'required|numeric|min:0',
                'duration' => 'required|integer|min:1',
                'description' => 'nullable|string',
            ]);

            unset($validated['company_id']);
            $service->update($validated);

            return response()->json([
                'message' => 'Service updated successfully',
                'data' => $service->fresh()
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Service not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Remove the specified service
     * DELETE /api/services/{id}/delete
     */
    public function destroy($id)
    {
        try {
            $service = Service::findOrFail($id);
            $serviceId = $service->id;
            
            // Remove this service ID from all discounts that reference it
            // Get all discounts that have services array (applies_to is specific_services)
            $discounts = Discount::where('applies_to', 'specific_services')
                ->whereNotNull('services')
                ->get();
            
            $updatedCount = 0;
            foreach ($discounts as $discount) {
                $services = $discount->services ?? [];
                
                // Check if service ID exists in the array
                if (is_array($services) && in_array($serviceId, $services)) {
                    // Remove the service ID from the array
                    $services = array_values(array_filter($services, function($sid) use ($serviceId) {
                        return (int)$sid !== (int)$serviceId;
                    }));
                    
                    // Update the discount with the cleaned services array
                    // If array is empty, set to null
                    $discount->services = empty($services) ? null : $services;
                    $discount->save();
                    $updatedCount++;
                }
            }
            
            // Delete the service
            $service->delete();

            return response()->json([
                'message' => 'Service deleted successfully',
                'data' => $service,
                'updated_discounts_count' => $updatedCount
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Service not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
