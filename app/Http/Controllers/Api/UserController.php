<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CompanyAccessService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:user.index')->only(['index']);
        $this->middleware('permission:user.show')->only(['show']);
        $this->middleware('permission:user.create')->only(['store']);
        $this->middleware('permission:user.edit')->only(['edit', 'update', 'assignPermissions']);
        $this->middleware('permission:user.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        try {
            $auth = $request->user();
            $query = User::with('roles', 'permissions');

            if ($auth->isSuperAdmin()) {
                if ($request->filled('company_id')) {
                    $query->where('company_id', $request->company_id);
                }
            } elseif ($auth->company_id) {
                $query->where('company_id', $auth->company_id);
            } else {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            if ($request->has('status')) {
                $status = (string) $request->status;

                if (in_array($status, ['0', '1'], true)) {
                    $query->where('status', $status);
                }
            }

            $per_page = $request->per_page ?? 10;

            $users = $query->when($request->filled('search'), function ($query) use ($request) {
                $query->where(function ($searchQuery) use ($request) {
                    $searchQuery->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            })->paginate($per_page);

            return \Helper::paginatedResponse($users);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function store(Request $request)
    {
        try {
            $auth = $request->user();

            if (! $auth->isSuperAdmin() && ! $auth->company_id) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            $data = $request->validate([
                'name' => 'string|required|max:30',
                'email' => 'string|required|unique:users',
                'password' => 'string|required',
                'role' => 'nullable|exists:roles,name,guard_name,api',
                'status' => 'required|in:1,0',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            if ($auth->isSuperAdmin()) {
                $data['company_id'] = $request->input('company_id');
            } else {
                $data['company_id'] = $auth->company_id;
            }

            if (! $auth->isSuperAdmin() && ! empty($data['role']) && in_array($data['role'], ['super_admin', 'company_admin'], true)) {
                return response()->json([
                    'message' => 'You cannot assign this role.',
                    'error' => 'forbidden',
                ], 422);
            }

            $data['password'] = Hash::make($request->password);
            $roleName = $data['role'] ?? null;
            unset($data['role']);

            $user = User::create($data);

            if (! empty($roleName)) {
                $role = Role::where('name', $roleName)
                    ->where('guard_name', 'api')
                    ->first();

                if ($role) {
                    $user->syncRoles($role);
                }
            }

            return response()->json(['message' => 'User Created Successfully', 'user' => $user->load('roles', 'permissions')], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $auth = $request->user();
            $user = User::with(['roles', 'permissions'])->findOrFail($id);

            if (! $auth->isSuperAdmin() && (int) $auth->company_id !== (int) $user->company_id) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            $data = $user->toArray();

            return response()->json(['message' => 'User fetched Successfully', 'user' => $data], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $auth = $request->user();
            $user = User::findOrFail($id);

            if (! $auth->isSuperAdmin() && (int) $auth->company_id !== (int) $user->company_id) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            if ($user->hasRole('super_admin') && ! $auth->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            $data = $request->validate([
                'name' => 'string|required|max:30',
                'email' => 'string|required|unique:users,email,' . $id,
                'password' => 'nullable',
                'role' => 'nullable|exists:roles,name,guard_name,api',
                'status' => 'required|in:1,0',
                'photo' => 'nullable|string',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            if ($auth->isCompanyAdmin() && ! $auth->isSuperAdmin()) {
                unset($data['company_id']);
                if (! empty($data['role']) && in_array($data['role'], ['super_admin', 'company_admin'], true)) {
                    return response()->json([
                        'message' => 'You cannot assign this role.',
                        'error' => 'forbidden',
                    ], 422);
                }
            }

            if (! empty($data['role'])) {
                $role = Role::where('name', $data['role'])
                    ->where('guard_name', 'api')
                    ->first();

                if ($role) {
                    $user->syncRoles($role);
                }
            } else {
                $user->syncRoles([]);
            }

            if (isset($data['password']) && $data['password']) {
                $data['password'] = Hash::make($request->password);
            } else {
                unset($data['password']);
            }

            unset($data['role']);

            if ($auth->isSuperAdmin() && array_key_exists('company_id', $data)) {
                $user->company_id = $data['company_id'];
                unset($data['company_id']);
            }

            $user->fill($data)->save();

            return response()->json(['message' => 'User Updated Successfully', 'user' => $user->load('roles', 'permissions')], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $auth = $request->user();
            $user = User::findOrFail($id);

            if ($user->hasRole('super_admin')) {
                return response()->json([
                    'message' => 'You cannot delete this user.',
                    'error' => 'forbidden',
                ], 403);
            }

            if (! $auth->isSuperAdmin() && (int) $auth->company_id !== (int) $user->company_id) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            if ((int) $auth->id === (int) $user->id) {
                return response()->json([
                    'message' => 'You cannot delete your own account.',
                    'error' => 'forbidden',
                ], 403);
            }

            $user->delete();

            return response()->json(['message' => 'User Deleted Successfully'], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function updateRole(Request $request, $id)
    {
        $data = $request->validate([
            'roles' => 'nullable|array',
            'roles.*' => 'required|exists:roles,name,guard_name,api',
            'role_names' => 'nullable|array',
            'role_names.*' => 'required|exists:roles,name,guard_name,api',
        ]);

        try {
            $auth = $request->user();

            if (! $auth->isSuperAdmin() && ! $auth->isCompanyAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            $user = User::findOrFail($id);

            if (! $auth->isSuperAdmin() && (int) $auth->company_id !== (int) $user->company_id) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            if ($user->hasRole('super_admin') && ! $auth->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            $rolesToSync = $data['role_names'] ?? $data['roles'] ?? [];

            if ($auth->isCompanyAdmin() && ! $auth->isSuperAdmin()) {
                foreach ($rolesToSync as $roleName) {
                    if (in_array($roleName, ['super_admin', 'company_admin'], true)) {
                        return response()->json([
                            'message' => 'You cannot assign this role.',
                            'error' => 'forbidden',
                        ], 422);
                    }
                }
            }

            $roles = Role::whereIn('name', $rolesToSync)
                ->where('guard_name', 'api')
                ->get();

            $user->syncRoles($roles);

            return response()->json([
                'message' => 'User roles synced successfully',
                'user' => $user->load('roles', 'permissions'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse('User not found', 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function assignPermissions(Request $request, $id)
    {
        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'required|string|exists:permissions,name,guard_name,api',
        ]);

        try {
            $auth = $request->user();

            if (! $auth->isSuperAdmin() && ! $auth->isCompanyAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            $user = User::findOrFail($id);

            if ($user->hasRole('super_admin') && ! $auth->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            if ($auth->isSuperAdmin()) {
                // full access
            } elseif ($auth->isCompanyAdmin() && (int) $auth->company_id === (int) $user->company_id) {
                $allowed = CompanyAccessService::allowedPermissionNamesForCompany($auth->company_id);
                foreach ($validated['permissions'] as $name) {
                    if (! in_array($name, $allowed, true)) {
                        return response()->json([
                            'message' => 'One or more permissions are not allowed for your company modules.',
                            'error' => 'forbidden',
                        ], 403);
                    }
                }
            } else {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            $permissions = Permission::whereIn('name', $validated['permissions'])
                ->where('guard_name', 'api')
                ->get();

            $user->syncPermissions($permissions);

            return response()->json([
                'message' => 'User permissions synced successfully',
                'user' => $user->load('roles', 'permissions'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse('User not found', 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
