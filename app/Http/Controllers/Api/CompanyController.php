<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\CompanySubscribePlan;
use App\Models\CompanySubscribePlanHistory;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyAccessService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            if (! $user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            $perPage = $request->per_page ?? 10;

            $query = Company::with(['subscription.plan','modules' => function ($q) {
                    $q->wherePivot('company_module_status', '1')->wherePivotNull('deleted_at');
                }]);

            if ($request->filled('search')) {
                $s = $request->search;
                $query->where(function ($q) use ($s) {
                    $q->where('company_name', 'like', '%' . $s . '%')
                        ->orWhere('company_email', 'like', '%' . $s . '%')
                        ->orWhere('ntn', 'like', '%' . $s . '%')
                        ->orWhere('company_phone', 'like', '%' . $s . '%');
                });
            }

            $companies = $query->orderBy('company_name')->paginate($perPage);

            $companies->getCollection()->transform(function ($company) {
                $company->logo_url = $company->company_logo ? url($company->company_logo) : null;
                $company->status_label = $company->company_status === '1' ? 'Active' : 'Inactive';
                $company->created_at_formatted = $company->created_at?->format('d-M-Y, g:i a');
                $company->updated_at_formatted = $company->updated_at?->format('d-M-Y, g:i a');

                return $company;
            });

            return \Helper::paginatedResponse($companies);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function store(Request $request)
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'error' => 'forbidden',
            ], 403);
        }

        $validated = $request->validate([
            'company_name'        => 'required|string|max:255',
            'company_email'       => 'required|email|max:255',
            'company_phone'       => 'required|string|max:50',
            'company_address'     => 'nullable|string',
            'company_city'        => 'nullable|string|max:100',
            'company_state'       => 'nullable|string|max:100',
            'company_zip'         => 'nullable|string|max:20',
            'company_country'     => 'nullable|string|max:100',
            'company_logo'        => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'company_website'     => 'nullable|string|max:255',
            'company_status'      => 'nullable|in:1,0,Active,Inactive',
            'company_notes'       => 'nullable|string',
            'company_description' => 'nullable|string',
            'company_type'        => 'nullable|string|max:100',
            'ntn'                 => 'nullable|string|max:100',
            'strn'                => 'nullable|string|max:100',
            'license_number'      => 'nullable|string|max:100',
            'module_ids'          => 'required|array|min:1',
            'module_ids.*'        => 'integer|exists:modules,id',
            'admin.name'          => 'required_without:admin_name|nullable|string|max:255',
            'admin_name'          => 'nullable|string|max:255',
            'admin.email'         => 'required_without:admin_email|nullable|email|max:255|unique:users,email',
            'admin_email'         => 'nullable|email|max:255|unique:users,email',
            'admin.password'      => 'required_without:admin_password|nullable|string|min:6',
            'admin_password'      => 'nullable|string|min:6',
            'admin.status'        => 'nullable|in:1,0',
            'plan_id'             => 'required|integer|exists:plans,id',
            'subscription_type'   => 'nullable|in:monthly,quarterly,yearly',
            'billing_cycle'       => 'nullable|in:monthly,quarterly,yearly',
            'payment_status'      => 'nullable|in:pending,paid,failed,refunded',
            'start_date'          => 'nullable|date',
            'license_start'       => 'nullable|date',
            'end_date'            => 'nullable|date',
            'expire_date'         => 'nullable|date',
            'license_expiry'      => 'nullable|date',
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
            $company = DB::transaction(function () use ($validated, $moduleIds, $request) {
                $companyData = collect($validated)->except([
                    'module_ids',
                    'admin',
                    'admin_name',
                    'admin_email',
                    'admin_password',
                    'admin_status',
                    'company_logo',
                    'subscription_type',
                    'billing_cycle',
                    'payment_status',
                    'start_date',
                    'license_start',
                    'end_date',
                    'expire_date',
                    'license_expiry',
                ])->all();

                if (! isset($companyData['company_status']) || $companyData['company_status'] === 'Active') {
                    $companyData['company_status'] = '1';
                } elseif ($companyData['company_status'] === 'Inactive') {
                    $companyData['company_status'] = '0';
                }

                $company = Company::create($companyData);

                // Handle Logo Upload
                $logoPath = $this->handleLogoUpload($request, $company);
                if ($logoPath) {
                    $company->company_logo = $logoPath;
                    $company->save();
                }

                $this->syncCompanyModules($company, $moduleIds);

                $company->refresh();
                $company->load(['modules' => function ($q) {
                    $q->wherePivot('company_module_status', '1')->wherePivotNull('deleted_at');
                }]);

                // Admin Account Setup
                $adminName = $request->input('admin.name', $request->input('admin_name'));
                $adminEmail = $request->input('admin.email', $request->input('admin_email'));
                $adminPassword = $request->input('admin.password', $request->input('admin_password'));
                $adminStatus = $request->input('admin.status', $request->input('admin_status', '1'));

                $adminUser = User::create([
                    'name' => $adminName,
                    'email' => $adminEmail,
                    'password' => Hash::make($adminPassword),
                    'status' => $adminStatus,
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

                // Subscription Setup
                $subType = $request->input('billing_cycle', $request->input('subscription_type', $request->input('type', 'monthly')));
                $subStart = $request->filled('start_date') || $request->filled('license_start')
                    ? Carbon::parse($request->input('start_date', $request->input('license_start')))
                    : now();

                if ($request->filled('end_date') || $request->filled('expire_date') || $request->filled('license_expiry')) {
                    $subEnd = Carbon::parse($request->input('end_date', $request->input('expire_date', $request->input('license_expiry'))));
                } else {
                    $subEnd = match ($subType) {
                        'monthly'   => $subStart->copy()->addMonth(),
                        'quarterly' => $subStart->copy()->addMonths(3),
                        'yearly'    => $subStart->copy()->addYear(),
                        default     => $subStart->copy()->addMonth(),
                    };
                }

                $paymentStatus = $request->input('payment_status', 'pending');

                CompanySubscribePlan::create([
                    'company_id'     => $company->id,
                    'plan_id'        => $validated['plan_id'],
                    'start_date'     => $subStart,
                    'end_date'       => $subEnd,
                    'type'           => $subType,
                    'payment_status' => $paymentStatus,
                    'is_active'      => 1,
                ]);

                CompanySubscribePlanHistory::create([
                    'company_id' => $company->id,
                    'plan_id'    => $validated['plan_id'],
                    'start_date' => $subStart,
                    'end_date'   => $subEnd,
                    'action'     => 'subscribed',
                ]);

                return $company->fresh([
                    'modules' => function ($q) {
                        $q->wherePivot('company_module_status', '1')->wherePivotNull('deleted_at');
                    },
                    'subscription.plan',
                    'plan',
                ]);
            });

            $responseCompany = $company->toArray();
            $responseCompany['logo_url'] = $company->company_logo ? url($company->company_logo) : null;
            $responseCompany['status_label'] = $company->company_status === '1' ? 'Active' : 'Inactive';
            $responseCompany['created_at_formatted'] = $company->created_at?->format('d-M-Y, g:i a');
            $responseCompany['updated_at_formatted'] = $company->updated_at?->format('d-M-Y, g:i a');

            return response()->json([
                'message' => 'Company and company admin created successfully.',
                'data' => $responseCompany,
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
                },'subscription.plan','users' => function ($q) {
                    $q->whereHas('roles', fn ($rq) => $rq->where('name', 'company_admin'));
                }])->findOrFail($id);

            if (! $user->isSuperAdmin() && (! $user->isCompanyAdmin() || (int) $user->company_id !== (int) $company->id)) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            $adminUser = $company->users->first() ?? User::where('company_id', $company->id)->first();

            $companyData = $company->toArray();
            $companyData['admin'] = $adminUser ? [
                'id' => $adminUser->id,
                'name' => $adminUser->name,
                'email' => $adminUser->email,
                'status' => $adminUser->status,
            ] : null;

            $companyData['logo_url'] = $company->company_logo ? url($company->company_logo) : null;
            $companyData['status_label'] = $company->company_status === '1' ? 'Active' : 'Inactive';
            $companyData['created_at_formatted'] = $company->created_at?->format('d-M-Y, g:i a');
            $companyData['updated_at_formatted'] = $company->updated_at?->format('d-M-Y, g:i a');

            return response()->json([
                'message' => 'Company fetched successfully',
                'data' => $companyData,
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
                    'company_name'        => 'required|string|max:255',
                    'company_email'       => 'nullable|email|max:255',
                    'company_phone'       => 'nullable|string|max:50',
                    'company_address'     => 'nullable|string',
                    'company_city'        => 'nullable|string|max:100',
                    'company_state'       => 'nullable|string|max:100',
                    'company_zip'         => 'nullable|string|max:20',
                    'company_country'     => 'nullable|string|max:100',
                    'company_logo'        => 'nullable',
                    'company_website'     => 'nullable|string|max:255',
                    'company_status'      => 'nullable|in:1,0,Active,Inactive',
                    'company_notes'       => 'nullable|string',
                    'company_description' => 'nullable|string',
                    'company_type'        => 'nullable|string|max:100',
                    'ntn'                 => 'nullable|string|max:100',
                    'strn'                => 'nullable|string|max:100',
                    'license_number'      => 'nullable|string|max:100',
                    'module_ids'          => 'sometimes|array|min:1',
                    'module_ids.*'        => 'integer|exists:modules,id',
                    'plan_id'             => 'nullable|integer|exists:plans,id',
                    'subscription_type'   => 'nullable|in:monthly,quarterly,yearly',
                    'billing_cycle'       => 'nullable|in:monthly,quarterly,yearly',
                    'payment_status'      => 'nullable|in:pending,paid,failed,refunded',
                    'start_date'          => 'nullable|date',
                    'license_start'       => 'nullable|date',
                    'end_date'            => 'nullable|date',
                    'expire_date'         => 'nullable|date',
                    'license_expiry'      => 'nullable|date',
                ]);
            } else {
                $validated = $request->validate([
                    'company_name'        => 'required|string|max:255',
                    'company_email'       => 'nullable|email|max:255',
                    'company_phone'       => 'nullable|string|max:50',
                    'company_address'     => 'nullable|string',
                    'company_city'        => 'nullable|string|max:100',
                    'company_state'       => 'nullable|string|max:100',
                    'company_zip'         => 'nullable|string|max:20',
                    'company_country'     => 'nullable|string|max:100',
                    'company_logo'        => 'nullable',
                    'company_website'     => 'nullable|string|max:255',
                    'company_status'      => 'nullable|in:1,0,Active,Inactive',
                    'company_notes'       => 'nullable|string',
                    'company_description' => 'nullable|string',
                    'company_type'        => 'nullable|string|max:100',
                    'ntn'                 => 'nullable|string|max:100',
                    'strn'                => 'nullable|string|max:100',
                    'license_number'      => 'nullable|string|max:100',
                ]);
            }

            DB::transaction(function () use ($company, $validated, $isSuper, $request) {
                $moduleIds = isset($validated['module_ids']) ? array_values(array_unique(array_map('intval', $validated['module_ids']))) : null;

                $companyData = collect($validated)->except([
                    'module_ids',
                    'company_logo',
                    'subscription_type',
                    'billing_cycle',
                    'payment_status',
                    'start_date',
                    'license_start',
                    'end_date',
                    'expire_date',
                    'license_expiry',
                ])->all();

                if (isset($companyData['company_status'])) {
                    if ($companyData['company_status'] === 'Active' || $companyData['company_status'] === 1 || $companyData['company_status'] === '1') {
                        $companyData['company_status'] = '1';
                    } elseif ($companyData['company_status'] === 'Inactive' || $companyData['company_status'] === 0 || $companyData['company_status'] === '0') {
                        $companyData['company_status'] = '0';
                    }
                }

                $company->update($companyData);

                // Handle Logo Upload if file provided
                if ($request->hasFile('company_logo')) {
                    $logoPath = $this->handleLogoUpload($request, $company);
                    if ($logoPath) {
                        $company->company_logo = $logoPath;
                        $company->save();
                    }
                }

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

                // SuperAdmin plan assign / update
                if ($isSuper && isset($validated['plan_id'])) {
                    $subType = $request->input('billing_cycle', $request->input('subscription_type', $request->input('type', 'monthly')));
                    $subStart = $request->filled('start_date') || $request->filled('license_start')
                        ? Carbon::parse($request->input('start_date', $request->input('license_start')))
                        : now();

                    if ($request->filled('end_date') || $request->filled('expire_date') || $request->filled('license_expiry')) {
                        $subEnd = Carbon::parse($request->input('end_date', $request->input('expire_date', $request->input('license_expiry'))));
                    } else {
                        $subEnd = match ($subType) {
                            'monthly'   => $subStart->copy()->addMonth(),
                            'quarterly' => $subStart->copy()->addMonths(3),
                            'yearly'    => $subStart->copy()->addYear(),
                            default     => $subStart->copy()->addMonth(),
                        };
                    }
                    $paymentStatus = $request->input('payment_status', 'pending');

                    // deactivate old subscription
                    CompanySubscribePlan::where('company_id', $company->id)
                        ->update(['is_active' => 0]);

                    // create new subscription
                    CompanySubscribePlan::create([
                        'company_id'     => $company->id,
                        'plan_id'        => $validated['plan_id'],
                        'start_date'     => $subStart,
                        'end_date'       => $subEnd,
                        'type'           => $subType,
                        'payment_status' => $paymentStatus,
                        'is_active'      => 1,
                    ]);

                    // history record
                    CompanySubscribePlanHistory::create([
                        'company_id' => $company->id,
                        'plan_id'    => $validated['plan_id'],
                        'start_date' => $subStart,
                        'end_date'   => $subEnd,
                        'action'     => 'subscribed',
                    ]);
                }
            });

            $freshCompany = Company::with([
                'modules' => function ($q) {
                    $q->wherePivotNull('deleted_at');
                },
                'subscription.plan',
                'plan',
            ])->findOrFail($id);

            $data = $freshCompany->toArray();
            $data['logo_url'] = $freshCompany->company_logo ? url($freshCompany->company_logo) : null;
            $data['status_label'] = $freshCompany->company_status === '1' ? 'Active' : 'Inactive';

            return response()->json([
                'message' => 'Company updated successfully',
                'data' => $data,
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

    protected function handleLogoUpload(Request $request, Company $company): ?string
    {
        if ($request->hasFile('company_logo')) {
            $file = $request->file('company_logo');
            $destinationPath = public_path('company_logos');
            if (! file_exists($destinationPath)) {
                mkdir($destinationPath, 0777, true);
            }

            if ($company->company_logo && file_exists(public_path($company->company_logo))) {
                @unlink(public_path($company->company_logo));
            }

            $slugName = Str::slug($company->company_name, '_');
            $ext = $file->getClientOriginalExtension();
            $fileName = $company->id . '_' . $slugName . '.' . $ext;

            $file->move($destinationPath, $fileName);

            return 'company_logos/' . $fileName;
        }

        return null;
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
