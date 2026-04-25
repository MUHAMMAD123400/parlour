<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Role;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:role.index')->only(['index']);
        $this->middleware('permission:role.show')->only(['show']);
        $this->middleware('permission:role.create')->only(['create', 'store']);
        $this->middleware('permission:role.edit')->only(['edit', 'update']);
        $this->middleware('permission:role.delete')->only(['destroy']);
        $this->middleware('role:super_admin')->only(['assignPermissions']);
    }

    protected function companyPermissionModuleKeys(Request $request): array
    {
        if ($request->user()->isSuperAdmin() || ! $request->user()->company_id) {
            return [];
        }

        $company = Company::find($request->user()->company_id);

        return $company ? $company->activePermissionModuleKeys() : [];
    }

    public function index(Request $request)
    {
        try {
            $per_page = $request->per_page ?? 10;
            $query = Role::query()->where('guard_name', 'api')->with('permissions');

            if (! $request->user()->isSuperAdmin()) {
                $query->where('company_id', $this->resolveAuthenticatedCompanyId($request->user()));
            }

            $roles = $query->when($request->filled('search'), function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->search . '%');
            })
                ->paginate($per_page);

            if (! $request->user()->isSuperAdmin()) {
                $keys = $this->companyPermissionModuleKeys($request);
                $roles->getCollection()->transform(function ($role) use ($keys) {
                    $role->setRelation(
                        'permissions',
                        $role->permissions->whereIn('module', $keys)->values()
                    );

                    return $role;
                });
            }

            return \Helper::paginatedResponse($roles);
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

        try {
            $validated = $request->validate([
                'company_id' => 'nullable|integer|exists:companies,id',
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('roles', 'name')->where(function ($q) use ($request) {
                        $q->where('guard_name', 'api');
                        if ($request->filled('company_id')) {
                            $q->where('company_id', $request->company_id);
                        } else {
                            $q->whereNull('company_id');
                        }
                    }),
                ],
                'description' => 'nullable|string',
                'permission_names' => 'nullable|array',
                'permission_names.*' => 'exists:permissions,name',
            ]);

            $role = Role::query()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'guard_name' => 'api',
                'company_id' => $validated['company_id'] ?? null,
            ]);

            if (! empty($validated['permission_names'])) {
                $role->syncPermissions($validated['permission_names']);
            }

            return response()->json([
                'message' => 'Role successfully created',
                'data' => $role->load('permissions'),
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function update(Request $request, $id)
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'error' => 'forbidden',
            ], 403);
        }

        try {
            $role = Role::findOrFail($id);

            $validated = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('roles', 'name')
                        ->ignore($role->id)
                        ->where(fn ($q) => $q->where('guard_name', 'api')->where('company_id', $role->company_id)),
                ],
                'description' => 'nullable|string',
                'permission_names' => 'nullable|array',
                'permission_names.*' => 'exists:permissions,name',
            ]);

            $role->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            if (! empty($validated['permission_names'])) {
                $role->syncPermissions($validated['permission_names']);
            }

            return response()->json([
                'message' => 'Role successfully updated',
                'data' => $role->load('permissions'),
            ]);
        } catch (ModelNotFoundException $e) {
            return errorResponse('Role not found', 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Delete a role safely (API guard compatible)
     */
    public function destroy(Request $request, $id)
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'error' => 'forbidden',
            ], 403);
        }

        try {
            $role = Role::findOrFail($id);

            if ($role->users()->count() > 0) {
                return response()->json([
                    'error' => 'Cannot delete role, it is assigned to users',
                ], 400);
            }

            $role->syncPermissions([]);

            $role->delete();

            return response()->json([
                'message' => 'Role deleted successfully',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Role not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error($e);

            return response()->json([
                'error' => 'Something went wrong',
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $role = Role::with('permissions')->findOrFail($id);

            if (! $request->user()->isSuperAdmin()) {
                if ((int) $role->company_id !== (int) $request->user()->company_id) {
                    return errorResponse('Role not found', 404);
                }
                $keys = $this->companyPermissionModuleKeys($request);
                $role->setRelation(
                    'permissions',
                    $role->permissions->whereIn('module', $keys)->values()
                );
            }

            return response()->json($role);
        } catch (ModelNotFoundException $e) {
            return errorResponse('Role not found', 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Sync permissions for a role (replace existing permissions)
     * POST /api/roles/{id}/assign-permissions
     */
    public function assignPermissions(Request $request, $id)
    {
        try {
            $role = Role::findOrFail($id);

            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'required|exists:permissions,name,guard_name,api',
            ]);

            $permissions = Permission::whereIn('name', $validated['permissions'])
                ->where('guard_name', 'api')
                ->get();

            $role->syncPermissions($permissions);

            return response()->json([
                'message' => 'Role permissions synced successfully',
                'data' => $role->load('permissions'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse('Role not found', 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
