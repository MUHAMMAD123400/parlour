<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class RoleController extends Controller
{
    public function __construct()
    {
        // $this->middleware('permission:role.index')->only(['index']);
        // $this->middleware('permission:role.show')->only(['show']);
        // $this->middleware('permission:role.create')->only(['create', 'store']);
        // $this->middleware('permission:role.edit')->only(['edit', 'update']);
        // $this->middleware('permission:role.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        try {
            $per_page = $request->per_page ?? 10;
            $roles = Role::where('guard_name', 'api')->with('permissions')->when($request->filled('search'), function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->search . '%');
            })
                ->paginate($per_page);
            return \Helper::paginatedResponse($roles);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }



    public function store(Request $request)
    {
        try {

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:roles,name,NULL,id,guard_name,api',
                'description' => 'nullable|string',
                'permission_names' => 'nullable|array',
                'permission_names.*' => 'exists:permissions,name',
            ]);

            $role = Role::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'guard_name' => 'api',
            ]);

            if (!empty($validated['permission_names'])) {
                $role->syncPermissions($validated['permission_names']);
            }

            return response()->json([
                'message' => 'Role successfully created',
                'data' => $role->load('permissions')
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $role = Role::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:roles,name,' . $role->id . ',id,guard_name,' . $role->guard_name,
                'description' => 'nullable|string',
                'permission_names' => 'nullable|array',
                'permission_names.*' => 'exists:permissions,name',
            ]);

            $role->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            if (!empty($validated['permission_names'])) {
                $role->syncPermissions($validated['permission_names']);
            }

            return response()->json([
                'message' => 'Role successfully updated',
                'data' => $role->load('permissions')
            ]);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Role not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Delete a role safely (API guard compatible)
     */
    public function destroy($id)
    {
        try {
            // Find the role
            $role = Role::findOrFail($id);
            $guardName = $role->guard_name ?? config('auth.defaults.guard');
                
            // Get the correct model class for this guard
            $usersModel = getModelForGuard($guardName);

            if (!$usersModel) {
                return response()->json([
                    'error' => "No model found for guard `{$guardName}`"
                ], 500);
            }

            // Check if any users are assigned to this role
            // Use model-based approach (safe for API)
            if ($usersModel::role($role->name, $guardName)->count() > 0) {
                return response()->json([
                    'error' => 'Cannot delete role, it is assigned to users'
                ], 400);
            }

            // Detach all permissions before deleting
            $role->syncPermissions([]);

            // Safe delete
            $role->delete();

            return response()->json([
                'message' => 'Role deleted successfully'
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Role not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'error' => 'Something went wrong'
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $role = Role::with('permissions')->findOrFail($id);
            return response()->json($role);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Role not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Assign permissions to a role
     * POST /api/roles/{id}/assign-permissions
     * 
     * If only 1 permission is provided: adds it without removing existing permissions
     * If multiple permissions are provided: replaces all existing permissions
     */
    public function assignPermissions(Request $request, $id)
    {
        try {
            $role = Role::findOrFail($id);

            $validated = $request->validate([
                'permission_names' => 'required|array',
                'permission_names.*' => 'required|exists:permissions,name,guard_name,api',
            ]);

            // Get Permission models with 'api' guard explicitly
            $permissions = Permission::whereIn('name', $validated['permission_names'])
                ->where('guard_name', 'api')
                ->get();

            // If only 1 permission, add it without removing existing ones
            // If multiple permissions, replace all existing permissions
            if (count($validated['permission_names']) === 1) {
                $role->givePermissionTo($permissions);
                $message = 'Permission added to role successfully';
            } else {
                $role->syncPermissions($permissions);
                $message = 'Permissions assigned to role successfully (replaced all existing)';
            }

            return response()->json([
                'message' => $message,
                'data' => $role->load('permissions')
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Role not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Add permissions to a role (without removing existing ones)
     * POST /api/roles/{id}/add-permissions
     */
    public function addPermissions(Request $request, $id)
    {
        try {
            $role = Role::findOrFail($id);

            $validated = $request->validate([
                'permission_names' => 'required|array',
                'permission_names.*' => 'required|exists:permissions,name,guard_name,api',
            ]);

            // Give permissions (add to existing permissions)
            $role->givePermissionTo($validated['permission_names']);

            return response()->json([
                'message' => 'Permissions added to role successfully',
                'data' => $role->load('permissions')
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Role not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Remove permissions from a role
     * POST /api/roles/{id}/remove-permissions
     */
    public function removePermissions(Request $request, $id)
    {
        try {
            $role = Role::findOrFail($id);

            $validated = $request->validate([
                'permission_names' => 'required|array',
                'permission_names.*' => 'required|exists:permissions,name,guard_name,api',
            ]);

            // Revoke permissions
            $role->revokePermissionTo($validated['permission_names']);

            return response()->json([
                'message' => 'Permissions removed from role successfully',
                'data' => $role->load('permissions')
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Role not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
