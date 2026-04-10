<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyAccessService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            if (! $user->isSuperAdmin() && ! $user->isCompanyAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            $perPage = $request->per_page ?? 10;

            if ($user->isSuperAdmin()) {
                $query = Company::with(['modules' => function ($q) {
                    $q->wherePivot('company_module_status', '1')->wherePivotNull('deleted_at');
                }]);

                if ($request->filled('search')) {
                    $s = $request->search;
                    $query->where(function ($q) use ($s) {
                        $q->where('company_name', 'like', '%' . $s . '%')
                            ->orWhere('company_email', 'like', '%' . $s . '%');
                    });
                }

                $companies = $query->orderBy('company_name')->paginate($perPage);

                return \Helper::paginatedResponse($companies);
            }

            $company = Company::with(['modules' => function ($q) {
                $q->wherePivot('company_module_status', '1')->wherePivotNull('deleted_at');
            }])->findOrFail($user->company_id);

            return response()->json([
                'data' => [$company],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 1,
                    'total' => 1,
                ],
                'totals' => [],
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function store(Request $request)
    {
        // dd($request->all(), $request->admin['name'],
        // $request->admin['email'],
        // $request->admin['password']);
        if (! $request->user()->isSuperAdmin()) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'error' => 'forbidden',
            ], 403);
        }

        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'company_email' => 'nullable|email|max:255',
            'company_phone' => 'nullable|string|max:50',
            'company_address' => 'nullable|string',
            'company_city' => 'nullable|string|max:100',
            'company_state' => 'nullable|string|max:100',
            'company_zip' => 'nullable|string|max:20',
            'company_country' => 'nullable|string|max:100',
            'company_logo' => 'nullable|string|max:500',
            'company_website' => 'nullable|string|max:255',
            'company_status' => 'nullable|in:1,0',
            'company_notes' => 'nullable|string',
            'company_description' => 'nullable|string',
            'module_ids' => 'required|array|min:1',
            'module_ids.*' => 'integer|exists:modules,id',
            'admin.name' => 'required|string|max:255',
            'admin.email' => 'required|email|max:255|unique:users,email',
            'admin.password' => 'required|string|min:6',
            'admin.status' => 'nullable|in:1,0',
        ]);
        
        $moduleIds = array_values(array_unique(array_map('intval', $validated['module_ids'])));

        $missingKey = Module::whereIn('id', $moduleIds)
            ->where(function ($q) {
                $q->whereNull('permission_module_key')->orWhere('permission_module_key', '');
            })
            ->exists();

        if ($missingKey) {
            return response()->json([
                'message' => 'Each selected module must have a permission_module_key set (maps to app permissions).',
                'error' => 'validation',
            ], 422);
        }

        try {
            $company = DB::transaction(function () use ($validated, $moduleIds) {
                $companyData = collect($validated)->except(['module_ids', 'admin'])->all();
                if (! isset($companyData['company_status'])) {
                    $companyData['company_status'] = '1';
                }

                $company = Company::create($companyData);

                $this->syncCompanyModules($company, $moduleIds);

                $company->refresh();
                $company->load(['modules' => function ($q) {
                    $q->wherePivot('company_module_status', '1')->wherePivotNull('deleted_at');
                }]);

                $admin = $validated['admin'];
                $status = $admin['status'] ?? '1';

                $adminUser = User::create([
                    'name' => $admin['name'],
                    'email' => $admin['email'],
                    'password' => Hash::make($admin['password']),
                    'status' => $status,
                    'company_id' => $company->id,
                ]);

                $adminRole = Role::query()->firstOrCreate(
                    [
                        'name' => 'company_admin',
                        'guard_name' => 'api',
                        'company_id' => $company->id,
                    ],
                    [
                        'description' => 'Company administrator; direct permissions from assigned company modules',
                    ]
                );

                if ($adminRole->wasRecentlyCreated) {
                    $source = Role::query()
                        ->where('name', 'company_admin')
                        ->where('guard_name', 'api')
                        ->where('company_id', '!=', $company->id)
                        ->whereNotNull('company_id')
                        ->first();
                    if ($source) {
                        $adminRole->syncPermissions($source->permissions);
                    }
                }

                $adminUser->syncRoles([$adminRole]);

                CompanyAccessService::grantCompanyAdminAllModulePermissions($adminUser, $company);

                return $company->fresh(['modules' => function ($q) {
                    $q->wherePivot('company_module_status', '1')->wherePivotNull('deleted_at');
                }]);
            });

            return response()->json([
                'message' => 'Company and company admin created successfully.',
                'data' => $company,
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            $company = Company::with(['modules' => function ($q) {
                $q->wherePivotNull('deleted_at');
            }])->findOrFail($id);

            if (! $user->isSuperAdmin() && (! $user->isCompanyAdmin() || (int) $user->company_id !== (int) $company->id)) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            return response()->json([
                'message' => 'Company fetched successfully',
                'data' => $company,
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e, 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            $company = Company::findOrFail($id);

            $isSuper = $user->isSuperAdmin();
            $isOwnCompanyAdmin = $user->isCompanyAdmin() && (int) $user->company_id === (int) $company->id;

            if (! $isSuper && ! $isOwnCompanyAdmin) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            if ($isSuper) {
                $validated = $request->validate([
                    'company_name' => 'required|string|max:255',
                    'company_email' => 'nullable|email|max:255',
                    'company_phone' => 'nullable|string|max:50',
                    'company_address' => 'nullable|string',
                    'company_city' => 'nullable|string|max:100',
                    'company_state' => 'nullable|string|max:100',
                    'company_zip' => 'nullable|string|max:20',
                    'company_country' => 'nullable|string|max:100',
                    'company_logo' => 'nullable|string|max:500',
                    'company_website' => 'nullable|string|max:255',
                    'company_status' => 'required|in:1,0',
                    'company_notes' => 'nullable|string',
                    'company_description' => 'nullable|string',
                    'module_ids' => 'sometimes|array|min:1',
                    'module_ids.*' => 'integer|exists:modules,id',
                ]);
            } else {
                $validated = $request->validate([
                    'company_name' => 'required|string|max:255',
                    'company_email' => 'nullable|email|max:255',
                    'company_phone' => 'nullable|string|max:50',
                    'company_address' => 'nullable|string',
                    'company_city' => 'nullable|string|max:100',
                    'company_state' => 'nullable|string|max:100',
                    'company_zip' => 'nullable|string|max:20',
                    'company_country' => 'nullable|string|max:100',
                    'company_logo' => 'nullable|string|max:500',
                    'company_website' => 'nullable|string|max:255',
                    'company_status' => 'required|in:1,0',
                    'company_notes' => 'nullable|string',
                    'company_description' => 'nullable|string',
                ]);
            }

            DB::transaction(function () use ($company, $validated, $isSuper) {
                $moduleIds = isset($validated['module_ids']) ? array_values(array_unique(array_map('intval', $validated['module_ids']))) : null;

                $company->update(collect($validated)->except(['module_ids'])->all());

                if ($isSuper && $moduleIds !== null) {
                    $missingKey = Module::whereIn('id', $moduleIds)
                        ->where(function ($q) {
                            $q->whereNull('permission_module_key')->orWhere('permission_module_key', '');
                        })
                        ->exists();

                    if ($missingKey) {
                        throw new \InvalidArgumentException('Each selected module must have permission_module_key set.');
                    }

                    $this->syncCompanyModules($company, $moduleIds);
                    CompanyAccessService::refreshCompanyAdminsPermissions($company->fresh(['modules']));
                }
            });

            return response()->json([
                'message' => 'Company updated successfully',
                'data' => $company->fresh(['modules']),
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'validation',
            ], 422);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function destroy(Request $request, $id)
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'error' => 'forbidden',
            ], 403);
        }

        try {
            $company = Company::findOrFail($id);
            $company->delete();

            return response()->json([
                'message' => 'Company deleted successfully',
                'data' => $company,
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    private function syncCompanyModules(Company $company, array $moduleIds): void
    {
        $moduleIds = array_values(array_unique(array_map('intval', $moduleIds)));

        CompanyModule::withTrashed()
            ->where('company_id', $company->id)
            ->whereNotIn('module_id', $moduleIds)
            ->get()
            ->each(fn (CompanyModule $cm) => $cm->delete());

        foreach ($moduleIds as $moduleId) {
            $cm = CompanyModule::withTrashed()->firstOrNew([
                'company_id' => $company->id,
                'module_id' => $moduleId,
            ]);
            $cm->company_module_status = '1';
            $cm->deleted_at = null;
            $cm->save();
        }
    }
}
