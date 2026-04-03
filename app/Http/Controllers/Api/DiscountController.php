<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\DiscountSetting;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscountController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:discount.index')->only(['index']);
        $this->middleware('permission:discount.show')->only(['show']);
        $this->middleware('permission:discount.create')->only(['store']);
        $this->middleware('permission:discount.edit')->only(['update']);
        $this->middleware('permission:discount.delete')->only(['destroy']);
    }

    /**
     * Get discount settings
     * GET /api/discounts/settings
     */
    public function getSettings(Request $request)
    {
        try {
            $user = $request->user();
            if ($user->isSuperAdmin()) {
                $request->validate([
                    'company_id' => 'required|integer|exists:companies,id',
                ]);
                $companyId = (int) $request->input('company_id');
            } else {
                $this->forbidGuestCompanyStaff($user);
                $companyId = (int) $user->company_id;
            }

            $settings = DiscountSetting::firstOrCreate(
                ['company_id' => $companyId],
                [
                    'staff_discount_limit' => 10,
                    'require_discount_reason' => true,
                ]
            );

            return response()->json([
                'message' => 'Discount settings fetched successfully',
                'data' => $settings,
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Update discount settings
     * POST /api/discounts/settings
     */
    public function updateSettings(Request $request)
    {
        try {
            $user = $request->user();
            $companyRule = $user->isSuperAdmin()
                ? ['required', 'integer', 'exists:companies,id']
                : ['prohibited'];

            $companyId = $user->isSuperAdmin()
                ? (int) $request->input('company_id')
                : (int) $user->company_id;

            if (! $user->isSuperAdmin()) {
                $this->forbidGuestCompanyStaff($user);
            }

            $validated = $request->validate([
                'company_id' => $companyRule,
                'staff_discount_limit' => 'required|integer|min:0|max:50',
                'require_discount_reason' => 'required',
            ]);

            $settings = DiscountSetting::updateOrCreate(
                ['company_id' => $companyId],
                [
                    'staff_discount_limit' => $validated['staff_discount_limit'],
                    'require_discount_reason' => $validated['require_discount_reason'],
                ]
            );

            return response()->json([
                'message' => 'Discount settings updated successfully',
                'data' => $settings,
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Display a listing of discounts/offers
     * GET /api/discounts
     */
    public function index(Request $request)
    {
        try {
            $per_page = $request->per_page ?? 10;

            $query = Discount::query();
            if ($cid = $this->optionalSuperAdminCompanyId($request)) {
                $query->where('company_id', $cid);
            }

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('offer_name', 'like', '%' . $search . '%')
                      ->orWhere('description', 'like', '%' . $search . '%');
                });
            }

            // Filter by discount type
            if ($request->filled('discount_type')) {
                $query->where('discount_type', $request->discount_type);
            }

            // Filter by applies_to
            if ($request->filled('applies_to')) {
                $query->where('applies_to', $request->applies_to);
            }

            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Filter active offers (status = '1' and within valid period)
            if ($request->filled('active_only')) {
                $query->where('status', '1')
                      ->where('valid_from', '<=', now())
                      ->where('valid_to', '>=', now());
            }

            // Filter scheduled offers (status = '1' but not yet started)
            if ($request->filled('scheduled_only')) {
                $query->where('status', '1')
                      ->where('valid_from', '>', now());
            }

            $discounts = $query->orderBy('created_at', 'desc')->paginate($per_page);
            
            // Load service models for each discount (services are stored as JSON array of IDs)
            // Access via: $discount->service_models
            $discounts->getCollection()->transform(function ($discount) {
                // The service_models attribute will be automatically available via the accessor
                $discount->service_models = $discount->serviceModels;
                return $discount;
            });

            return \Helper::paginatedResponse($discounts);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Store a newly created discount/offer
     * POST /api/discounts/store
     */
    public function store(Request $request)
    {
        try {
            // Handle JSON string arrays for categories and services
            $requestData = $request->all();
            if (isset($requestData['categories']) && is_string($requestData['categories'])) {
                $decoded = json_decode($requestData['categories'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $requestData['categories'] = $decoded;
                    $request->merge(['categories' => $decoded]);
                }
            }
            if (isset($requestData['services']) && is_string($requestData['services'])) {
                $decoded = json_decode($requestData['services'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $requestData['services'] = $decoded;
                    $request->merge(['services' => $decoded]);
                }
            }

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
                'offer_name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'discount_type' => 'required|in:percentage,fixed',
                'discount_value' => 'required|numeric|min:0',
                'applies_to' => 'required|in:all_services,specific_categories,specific_services',
                'categories' => 'nullable|array',
                'categories.*' => [
                    'integer',
                    Rule::exists('categories', 'id')->where('company_id', $companyId),
                ],
                'services' => 'nullable|array',
                'services.*' => [
                    'integer',
                    Rule::exists('services', 'id')->where('company_id', $companyId),
                ],
                'valid_from' => 'required|date',
                'valid_to' => 'required|date|after_or_equal:valid_from',
                'auto_apply' => 'nullable',
                'status' => 'nullable|in:1,0',
            ]);

            // Set default values and convert auto_apply to boolean
            if (!isset($validated['auto_apply'])) {
                $validated['auto_apply'] = false;
            } else {
                // Convert string 'true'/'false', '1'/'0', or actual boolean to boolean
                if (is_string($validated['auto_apply'])) {
                    $validated['auto_apply'] = in_array(strtolower($validated['auto_apply']), ['true', '1', 'yes'], true);
                } else {
                    $validated['auto_apply'] = (bool) $validated['auto_apply'];
                }
            }
            if (!isset($validated['status'])) {
                $validated['status'] = '1';
            }

            // Validate categories/services based on applies_to
            if ($validated['applies_to'] === 'specific_categories' && empty($validated['categories'])) {
                return errorResponse('Categories are required when applies_to is specific_categories', 422);
            }
            if ($validated['applies_to'] === 'specific_services' && empty($validated['services'])) {
                return errorResponse('Services are required when applies_to is specific_services', 422);
            }

            // Clear categories/services if not applicable
            if ($validated['applies_to'] === 'all_services') {
                $validated['categories'] = null;
                $validated['services'] = null;
            } elseif ($validated['applies_to'] === 'specific_categories') {
                $validated['services'] = null;
            } elseif ($validated['applies_to'] === 'specific_services') {
                $validated['categories'] = null;
            }

            $validated['company_id'] = $companyId;
            $discount = Discount::create($validated);

            return response()->json([
                'message' => 'Discount/Offer created successfully',
                'data' => $discount,
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Display the specified discount/offer
     * GET /api/discounts/{id}/show
     */
    public function show($id)
    {
        try {
            $discount = Discount::findOrFail($id);
            
            return response()->json([
                'message' => 'Discount/Offer fetched successfully',
                'data' => $discount
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Discount/Offer not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Update the specified discount/offer
     * POST /api/discounts/{id}/update
     */
    public function update(Request $request, $id)
    {
        try {
            $this->forbidGuestCompanyStaff($request->user());

            $discount = Discount::findOrFail($id);
            $companyId = (int) $discount->company_id;

            // Handle JSON string arrays for categories and services
            $requestData = $request->all();
            if (isset($requestData['categories']) && is_string($requestData['categories'])) {
                $decoded = json_decode($requestData['categories'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $requestData['categories'] = $decoded;
                    $request->merge(['categories' => $decoded]);
                }
            }
            if (isset($requestData['services']) && is_string($requestData['services'])) {
                $decoded = json_decode($requestData['services'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $requestData['services'] = $decoded;
                    $request->merge(['services' => $decoded]);
                }
            }

            $validated = $request->validate([
                'company_id' => 'prohibited',
                'offer_name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'discount_type' => 'required|in:percentage,fixed',
                'discount_value' => 'required|numeric|min:0',
                'applies_to' => 'required|in:all_services,specific_categories,specific_services',
                'categories' => 'nullable|array',
                'categories.*' => [
                    'integer',
                    Rule::exists('categories', 'id')->where('company_id', $companyId),
                ],
                'services' => 'nullable|array',
                'services.*' => [
                    'integer',
                    Rule::exists('services', 'id')->where('company_id', $companyId),
                ],
                'valid_from' => 'required|date',
                'valid_to' => 'required|date|after_or_equal:valid_from',
                'auto_apply' => 'nullable',
                'status' => 'nullable|in:1,0',
            ]);

            unset($validated['company_id']);

            // Convert auto_apply to boolean if provided
            if (isset($validated['auto_apply'])) {
                // Convert string 'true'/'false', '1'/'0', or actual boolean to boolean
                if (is_string($validated['auto_apply'])) {
                    $validated['auto_apply'] = in_array(strtolower($validated['auto_apply']), ['true', '1', 'yes'], true);
                } else {
                    $validated['auto_apply'] = (bool) $validated['auto_apply'];
                }
            }

            // Validate categories/services based on applies_to
            if ($validated['applies_to'] === 'specific_categories' && empty($validated['categories'])) {
                return errorResponse('Categories are required when applies_to is specific_categories', 422);
            }
            if ($validated['applies_to'] === 'specific_services' && empty($validated['services'])) {
                return errorResponse('Services are required when applies_to is specific_services', 422);
            }

            // Clear categories/services if not applicable
            if ($validated['applies_to'] === 'all_services') {
                $validated['categories'] = null;
                $validated['services'] = null;
            } elseif ($validated['applies_to'] === 'specific_categories') {
                $validated['services'] = null;
            } elseif ($validated['applies_to'] === 'specific_services') {
                $validated['categories'] = null;
            }

            $discount->update($validated);

            return response()->json([
                'message' => 'Discount/Offer updated successfully',
                'data' => $discount->fresh()
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Discount/Offer not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Remove the specified discount/offer
     * DELETE /api/discounts/{id}/delete
     */
    public function destroy($id)
    {
        try {
            $discount = Discount::findOrFail($id);
            $discount->delete();

            return response()->json([
                'message' => 'Discount/Offer deleted successfully',
                'data' => $discount
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Discount/Offer not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
