<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Permission\Models\Role;
use Exception;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:user.index')->only(['index']);
        $this->middleware('permission:user.show')->only(['show']);
        $this->middleware('permission:user.create')->only(['create', 'store']);
        $this->middleware('permission:user.edit')->only(['edit', 'update']);
        $this->middleware('permission:user.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        try {
            $query = User::with('roles', 'permissions');

            // Filter by status
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

            $data = $request->validate([
                'name' => 'string|required|max:30',
                'email' => 'string|required|unique:users',
                'password' => 'string|required',
                'role' => 'nullable|exists:roles,name,guard_name,api',
                'status' => 'required|in:1,0',
            ]);

            $data['password'] = Hash::make($request->password);
            $user = User::create($data);

            // Assign role with 'api' guard
            if (!empty($data['role'])) {
                $role = Role::where('name', $data['role'])
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

    public function show($id)
    {
        try {
        $user = User::with(['roles', 'permissions'])->findOrFail($id);
        $data = $user->toArray();
        return response()->json(['message' => 'User fetched Successfully', 'user' => $data], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function update(Request $request, $id)
    {

        try {
            $data = $request->validate([
                'name' => 'string|required|max:30',
                'email' => 'string|required|unique:users,email,' . $id,
                'password' => 'nullable',
                'role' => 'nullable|exists:roles,name,guard_name,api',
                'status' => 'required|in:1,0',
                'photo' => 'nullable|string',
            ]);

            $user = User::findOrFail($id);

            // Assign role with 'api' guard
            if (!empty($data['role'])) {
                $role = Role::where('name', $data['role'])
                    ->where('guard_name', 'api')
                    ->first();

                if ($role) {
                    $user->syncRoles($role);
                }
            } else {
                // If no role provided, remove all roles
                $user->syncRoles([]);
            }

            if (isset($data['password']) && $data['password']) {
                $data['password'] = Hash::make($request->password);
            } else {
                unset($data['password']); // remove it so it won't update
            }

            // Remove role from data array since we handle it separately
            unset($data['role']);

            $user->fill($data)->save();

            return response()->json(['message' => 'User Updated Successfully', 'user' => $user->load('roles')], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function destroy($id)
    {
        try {
            $user = User::findOrFail($id);
            $user->delete();
            return response()->json(['message' => 'User Deleted Successfully'], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }


    public function updateRole(Request $request, $id)
    {
        $data = $request->validate([
            // Support both keys for backward compatibility.
            'roles' => 'nullable|array',
            'roles.*' => 'required|exists:roles,name,guard_name,api',
            'role_names' => 'nullable|array',
            'role_names.*' => 'required|exists:roles,name,guard_name,api',
        ]);

        try {
            $user = User::findOrFail($id);

            $rolesToSync = $data['role_names'] ?? $data['roles'] ?? [];

            $roles = Role::whereIn('name', $rolesToSync)
                ->where('guard_name', 'api')
                ->get();

            // Single source of truth: always replace existing roles.
            $user->syncRoles($roles);

            return response()->json([
                'message' => 'User roles synced successfully',
                'user' => $user->load('roles', 'permissions')
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("User not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

}
