<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Customer;
use App\Models\Module;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\CompanyAccessService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CompanyReportController extends Controller
{
    /**
     * Resolve company and check authorization (Strictly for Super Admin)
     */
    private function resolveCompany(Request $request, $id = null): Company
    {
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            abort(response()->json([
                'message' => 'You do not have permission to perform this action.',
                'error'   => 'forbidden',
            ], 403));
        }

        $companyId = $id ?? $request->input('company_id');

        if (! $companyId) {
            abort(response()->json([
                'message' => 'company_id is required.',
                'error'   => 'validation',
            ], 422));
        }

        return Company::with(['subscription.plan', 'plan'])->findOrFail($companyId);
    }

    /**
     * 1. Overview Tab
     * GET /api/super-admin/company-report/{id}/overview
     */
    public function overview(Request $request, $id = null)
    {
        try {
            $company = $this->resolveCompany($request, $id);

            $subscription = $company->subscription;
            $plan = $subscription?->plan ?? $company->plan;

            $staffCount = User::where('company_id', $company->id)->count();
            $activeStaffCount = User::where('company_id', $company->id)->where('status', '1')->count();

            $customerCount = Customer::where('company_id', $company->id)->count();
            $activeCustomerCount = Customer::where('company_id', $company->id)->whereHas('bills')->count();
            if ($activeCustomerCount === 0 && $customerCount > 0) {
                $activeCustomerCount = $customerCount;
            }

            $serviceCount = Service::where('company_id', $company->id)->count();
            $productCount = Product::where('company_id', $company->id)->count();

            return response()->json([
                'message' => 'Company overview fetched successfully',
                'data' => [
                    'company' => [
                        'id' => $company->id,
                        'company_name' => $company->company_name,
                        'company_email' => $company->company_email,
                        'company_phone' => $company->company_phone,
                        'company_status' => (string) $company->company_status,
                        'status_label' => $company->company_status === '1' ? 'Active' : 'Inactive',
                        'company_logo' => $company->company_logo,
                        'company_website' => $company->company_website,
                        'company_notes' => $company->company_notes,
                        'company_description' => $company->company_description,
                        'location' => [
                            'address' => $company->company_address,
                            'city' => $company->company_city,
                            'state' => $company->company_state,
                            'zip' => $company->company_zip,
                            'country' => $company->company_country,
                        ],
                        'created_at' => $company->created_at,
                        'created_at_formatted' => $company->created_at?->format('d-M-Y, g:i a'),
                        'updated_at' => $company->updated_at,
                        'updated_at_formatted' => $company->updated_at?->format('d-M-Y, g:i a'),
                    ],
                    'subscription' => [
                        'plan_id' => $plan?->id,
                        'plan_name' => $plan?->name ?? '—',
                        'license_expiry' => $subscription?->end_date?->format('Y-m-d') ?? '—',
                        'license_expiry_formatted' => $subscription?->end_date?->format('d-M-Y') ?? '—',
                        'days_left' => $subscription?->end_date ? (int) now()->diffInDays($subscription->end_date, false) : null,
                        'staff_limit' => $plan?->staff_limit ?? '—',
                        'customer_limit' => $plan?->customer_limit ?? '—',
                        'payment_status' => $subscription?->payment_status ? ucfirst($subscription->payment_status) : '—',
                        'subscription_type' => $subscription?->type ?? '—',
                        'is_active' => $subscription ? (bool) $subscription->is_active : false,
                    ],
                    'statistics' => [
                        'staff_members' => $staffCount,
                        'active_staff' => $activeStaffCount,
                        'customers' => $customerCount,
                        'active_customers' => $activeCustomerCount,
                        'services' => $serviceCount,
                        'products' => $productCount,
                    ],
                ],
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e, 404);
        }
    }

    /**
     * 2. Staff Tab
     * GET /api/super-admin/company-report/{id}/staff
     */
    public function staff(Request $request, $id = null)
    {
        try {
            $company = $this->resolveCompany($request, $id);
            $perPage = (int) $request->input('per_page', 10);

            $staff = User::where('company_id', $company->id)
                ->with('roles')
                ->latest()
                ->paginate($perPage);

            $staff->getCollection()->transform(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->roles->first()?->name ?? 'Staff',
                    'joined' => $u->created_at?->format('d M Y'),
                    'status' => $u->status === '1' ? 'Active' : 'Inactive',
                    'status_raw' => $u->status,
                ];
            });

            return \Helper::paginatedResponse($staff);
        } catch (Exception $e) {
            return errorResponse($e, 404);
        }
    }

    /**
     * 3. Customers Tab
     * GET /api/super-admin/company-report/{id}/customers
     */
    public function customers(Request $request, $id = null)
    {
        try {
            $company = $this->resolveCompany($request, $id);
            $perPage = (int) $request->input('per_page', 10);

            $customers = Customer::where('company_id', $company->id)
                ->withCount('bills')
                ->withMax('bills', 'created_at')
                ->latest()
                ->paginate($perPage);

            $customers->getCollection()->transform(function ($c) {
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'phone' => $c->phone,
                    'email' => $c->email,
                    'total_visits' => (int) $c->bills_count,
                    'last_visit' => $c->bills_max_created_at ? Carbon::parse($c->bills_max_created_at)->format('d M Y') : '—',
                    'status' => $c->bills_count > 0 ? 'Active' : 'Inactive',
                ];
            });

            return \Helper::paginatedResponse($customers);
        } catch (Exception $e) {
            return errorResponse($e, 404);
        }
    }

    /**
     * 4. Services Tab
     * GET /api/super-admin/company-report/{id}/services
     */
    public function services(Request $request, $id = null)
    {
        try {
            $company = $this->resolveCompany($request, $id);
            $perPage = (int) $request->input('per_page', 10);

            $services = Service::where('company_id', $company->id)
                ->with('category')
                ->latest()
                ->paginate($perPage);

            $services->getCollection()->transform(function ($s) {
                return [
                    'id' => $s->id,
                    'name' => $s->service_name,
                    'category' => $s->category?->category_name ?? '—',
                    'price' => (float) $s->price,
                    'price_formatted' => 'Rs. ' . number_format((float) $s->price),
                    'duration' => $s->duration ? $s->duration . ' min' : '—',
                    'status' => $s->status === '1' ? 'Active' : 'Inactive',
                    'status_raw' => $s->status,
                ];
            });

            return \Helper::paginatedResponse($services);
        } catch (Exception $e) {
            return errorResponse($e, 404);
        }
    }

    /**
     * 5. Products Tab
     * GET /api/super-admin/company-report/{id}/products
     */
    public function products(Request $request, $id = null)
    {
        try {
            $company = $this->resolveCompany($request, $id);
            $perPage = (int) $request->input('per_page', 10);

            $products = Product::where('company_id', $company->id)
                ->with('category')
                ->latest()
                ->paginate($perPage);

            $products->getCollection()->transform(function ($p) {
                $stock = (int) $p->quantity_in_stock;
                return [
                    'id' => $p->id,
                    'name' => $p->product_name,
                    'brand' => $p->brand ?? '—',
                    'category' => $p->category?->category_name ?? '—',
                    'price' => (float) $p->selling_price,
                    'price_formatted' => 'Rs. ' . number_format((float) $p->selling_price),
                    'stock' => $stock,
                    'stock_formatted' => $stock > 0 ? "{$stock} " . ($p->unit ?? 'units') : '0 units (Out of stock)',
                    'status' => $stock > 0 ? 'Active' : 'Inactive',
                ];
            });

            return \Helper::paginatedResponse($products);
        } catch (Exception $e) {
            return errorResponse($e, 404);
        }
    }

    /**
     * 6. Access Tab (Roles, Categories, IP Whitelist)
     * GET /api/super-admin/company-report/{id}/access
     */
    public function access(Request $request, $id = null)
    {
        try {
            $company = $this->resolveCompany($request, $id);

            // Roles
            $roles = Role::where('company_id', $company->id)
                ->with('permissions')
                ->get()
                ->map(function ($r) use ($company) {
                    $usersCount = DB::table('model_has_roles')
                        ->where('role_id', $r->id)
                        ->whereIn('model_id', User::where('company_id', $company->id)->pluck('id'))
                        ->count();
                    $permissionsCount = $r->permissions ? $r->permissions->count() : 0;

                    $uText = $usersCount === 1 ? '1 user' : "{$usersCount} users";
                    $pText = $permissionsCount === 1 ? '1 permission' : "{$permissionsCount} permissions";
                    return [
                        'id' => $r->id,
                        'name' => $r->name,
                        'users_count' => (int) $usersCount,
                        'permissions_count' => (int) $permissionsCount,
                        'subtitle' => "{$uText} • {$pText}",
                        'status' => 'Active',
                    ];
                });

            // Categories
            $categories = Category::where('company_id', $company->id)
                ->withCount(['services', 'products'])
                ->get()
                ->map(function ($c) {
                    $isService = $c->services_count >= $c->products_count;
                    $type = $isService ? 'Service' : 'Product';
                    $count = $isService ? $c->services_count : $c->products_count;
                    return [
                        'id' => $c->id,
                        'name' => $c->category_name,
                        'type' => $type,
                        'items_count' => (int) $count,
                        'subtitle' => "{$type} • {$count} items",
                        'status' => $c->status === '1' ? 'Active' : 'Inactive',
                    ];
                });

            return response()->json([
                'message' => 'Access data fetched successfully',
                'data' => [
                    'roles' => [
                        'total' => $roles->count(),
                        'items' => $roles,
                    ],
                    'categories' => [
                        'total' => $categories->count(),
                        'items' => $categories,
                    ],
                    'ip_whitelist' => [
                        'total' => 0,
                        'items' => [],
                    ],
                ],
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e, 404);
        }
    }

    /**
     * 7. Toggle Module Status
     * POST /api/super-admin/company-report/{id}/toggle-module-status
     */
    public function toggleModuleStatus(Request $request, $id = null)
    {
        try {
            $company = $this->resolveCompany($request, $id);

            $validated = $request->validate([
                'module_id' => 'required|integer|exists:modules,id',
                'status'    => 'nullable|in:1,0,true,false',
            ]);

            $module = Module::findOrFail($validated['module_id']);

            $companyModule = CompanyModule::withTrashed()->firstOrNew([
                'company_id' => $company->id,
                'module_id'  => $module->id,
            ]);

            if ($request->has('status') && $request->input('status') !== null) {
                $statusInput = $request->input('status');
                $newStatus = ($statusInput === '1' || $statusInput === 1 || $statusInput === true || $statusInput === 'true') ? '1' : '0';
            } else {
                $newStatus = ($companyModule->company_module_status === '1') ? '0' : '1';
            }

            $companyModule->company_module_status = $newStatus;
            $companyModule->deleted_at = null;
            $companyModule->save();

            CompanyAccessService::refreshCompanyAdminsPermissions($company->fresh(['modules']));

            $statusLabel = $newStatus === '1' ? 'activated' : 'deactivated';

            return response()->json([
                'message' => "Module {$module->module_name} {$statusLabel} successfully for company.",
                'data' => [
                    'company_id'  => $company->id,
                    'module_id'   => $module->id,
                    'module_name' => $module->module_name,
                    'status'      => $newStatus,
                    'is_active'   => $newStatus === '1',
                ],
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
