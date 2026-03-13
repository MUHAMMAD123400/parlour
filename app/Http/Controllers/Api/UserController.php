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
        // $this->middleware('permission:user.index')->only(['index']);
        // $this->middleware('permission:user.show')->only(['show']);
        // $this->middleware('permission:user.create')->only(['create', 'store']);
        // $this->middleware('permission:user.edit')->only(['edit', 'update']);
        // $this->middleware('permission:user.delete')->only(['destroy']);
        // $this->middleware('permission:invoice.assign.team.user')->only(['fetchForAssignment']);
    }

    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $query = User::with('roles', 'permissions');

            // If user doesn't have user.all permission, show only team users
            // if ($user->cannot('user.all')) {
            //     // Get all user IDs from the same teams as this user
            //     $teamUserIds = $user->teams()
            //         ->with('user:id')
            //         ->get()
            //         ->pluck('user')
            //         ->flatten()
            //         ->pluck('id')
            //         ->unique()
            //         ->values();

            //     if ($teamUserIds->isEmpty()) {
            //         $query->whereRaw('1 = 0'); // return no records
            //     } else {
            //         $query->whereIn('id', $teamUserIds);
            //     }
            // }

            $per_page = $request->per_page ?? 10;

            $users = $query->when($request->filled('search'), function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%');
            })->paginate($per_page);

            return \Helper::paginatedResponse($users);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'string|required|max:30',
            'email' => 'string|required|unique:users',
            'password' => 'string|required',
            'role' => 'nullable|exists:roles,name',
            'status' => 'required|in:active,inactive',
            'photo' => 'nullable|string',
        ]);
        try {
            $data['password'] = Hash::make($request->password);
            // $data['display_password'] = $request->password;
            $user = User::create($data);
            if ($data['role'])
                $user->syncRoles($data['role']);

            return response()->json(['message' => 'User Created Successfully', 'user' => $user], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function show($id)
    {
        // $authUser = auth()->user();
        // if($authUser->cannot('users.show')){
        //     abort(403);
        // }
        // try {
        $user = User::with(['roles', 'permissions'])->findOrFail($id);
        // dd($user->display_password);
        // $user->display_password = $user->display_password;
        $data = $user->toArray();
        // $data['display_password'] = $user->display_password;
        return response()->json(['message' => 'User fetched Successfully', 'user' => $data], 200);
        // } catch (Exception $e) {
        //     return errorResponse($e);
        // }
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'name' => 'string|required|max:30',
            // 'email' => 'string|required|unique:users',
            'password' => 'nullable',
            'role' => 'nullable|exists:roles,name',
            'status' => 'required|in:active,inactive',
            'photo' => 'nullable|string',
            // 'permissions' => 'nullable|array',
            // 'permissions.*' => 'required|exists:permissions,name'
        ]);

        try {

            $user = User::findOrFail($id);
            // $user->syncPermissions($data['permissions'] ?? []);
            $user->syncRoles($data['role']);

            if ($data['password']) {
                $data['password'] = Hash::make($request->password);
            } else {
                unset($data['password']); // remove it so it won't update
            }


            $user->fill($data)->save();

            return response()->json(['message' => 'User Updated Successfully', 'user' => $user->load('permissions', 'roles')], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    // public function updatePermissions(Request $request, $id)
    // {
    //     $data = $request->validate([
    //         'permissions' => 'nullable|array',
    //         'permissions.*' => 'required|exists:permissions,name,guard_name,api',
    //     ]);

    //     try {
    //         $user = User::findOrFail($id);

    //         // Set guard explicitly
    //         $user->guard_name = 'api';

    //         // Now sync permissions
    //         $user->syncPermissions($data['permissions'] ?? []);

    //         return response()->json([
    //             'message' => 'User Permissions Updated Successfully',
    //             'user' => $user->load('permissions', 'roles')
    //         ], 200);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'message' => 'Something went wrong',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function updatePermissions(Request $request, $id)
    {
        $data = $request->validate([
            'roles' => 'nullable|array',
            'roles.*' => 'required|exists:roles,name,guard_name,api',
        ]);

        try {
            $user = User::findOrFail($id);

            // Ensure correct guard
            $user->guard_name = 'api';

            // Sync roles instead of permissions
            $user->syncRoles($data['roles'] ?? []);

            return response()->json([
                'message' => 'User Roles Updated Successfully',
                'user' => $user->load('roles', 'permissions')
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign roles to a user
     * POST /api/users/{id}/assign-roles
     * 
     * If only 1 role is provided: adds it without removing existing roles
     * If multiple roles are provided: replaces all existing roles
     */
    public function assignRoles(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validated = $request->validate([
                'role_names' => 'required|array',
                'role_names.*' => 'required|exists:roles,name,guard_name,api',
            ]);

            // Get Role models with 'api' guard explicitly
            $roles = Role::whereIn('name', $validated['role_names'])
                ->where('guard_name', 'api')
                ->get();
            
            // If only 1 role, add it without removing existing ones
            // If multiple roles, replace all existing roles
            if (count($validated['role_names']) === 1) {
                $user->assignRole($roles);
                $message = 'Role added to user successfully';
            } else {
                $user->syncRoles($roles);
                $message = 'Roles assigned to user successfully (replaced all existing)';
            }

            return response()->json([
                'message' => $message,
                'user' => $user->load('roles', 'permissions')
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("User not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Add roles to a user (without removing existing ones)
     * POST /api/users/{id}/add-roles
     */
    public function addRoles(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validated = $request->validate([
                'role_names' => 'required|array',
                'role_names.*' => 'required|exists:roles,name,guard_name,api',
            ]);

            // Get Role models with 'api' guard explicitly
            $roles = Role::whereIn('name', $validated['role_names'])
                ->where('guard_name', 'api')
                ->get();
            
            // Assign roles using Role models (this ensures correct guard)
            $user->assignRole($roles);

            return response()->json([
                'message' => 'Roles added to user successfully',
                'user' => $user->load('roles', 'permissions')
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("User not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Remove roles from a user
     * POST /api/users/{id}/remove-roles
     */
    public function removeRoles(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validated = $request->validate([
                'role_names' => 'required|array',
                'role_names.*' => 'required|exists:roles,name,guard_name,api',
            ]);

            // Get Role models with 'api' guard explicitly
            $roles = Role::whereIn('name', $validated['role_names'])
                ->where('guard_name', 'api')
                ->get();
            
            // Remove roles using Role models (this ensures correct guard)
            $user->removeRole($roles);

            return response()->json([
                'message' => 'Roles removed from user successfully',
                'user' => $user->load('roles', 'permissions')
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("User not found", 404);
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

    // /**
    //  * Get logged-in user profile with assigned brands, units, teams and team members
    //  * GET /api/v2/profile
    //  * 
    //  * @param Request $request
    //  * @return \Illuminate\Http\JsonResponse
    //  */
    // public function profile(Request $request)
    // {
    //     try {
    //         $user = auth()->user();

    //         // Load user with roles and permissions
    //         $user->load(['roles', 'permissions']);

    //         // Get all permissions: direct permissions + permissions from roles
    //         $allPermissions = $user->getAllPermissions()->pluck('name');

    //         // Load teams with their brands, units, and team members
    //         $teams = $user->teams()
    //             ->with([
    //                 'teamBrands' => function ($query) {
    //                     $query->select('brands.id', 'brands.title', 'brands.domain', 'brands.unit_id');
    //                 },
    //                 'unit' => function ($query) {
    //                     $query->select('units.id', 'units.title');
    //                 },
    //                 'user' => function ($query) {
    //                     $query->select('users.id', 'users.name', 'users.email', 'users.photo', 'users.status');
    //                 }
    //             ])
    //             ->get();

    //         // Get unique brands from all teams
    //         $brandIds = collect();
    //         foreach ($teams as $team) {
    //             $brandIds = $brandIds->merge($team->teamBrands->pluck('id'));
    //         }
    //         $brandIds = $brandIds->unique()->values();

    //         $brands = \App\Models\Brand::whereIn('id', $brandIds)
    //             ->with(['unit' => function ($query) {
    //                 $query->select('units.id', 'units.title');
    //             }])
    //             ->select('id', 'title', 'domain', 'unit_id')
    //             ->get();

    //         // Get unique units from teams and brands
    //         $unitIds = collect();
    //         foreach ($teams as $team) {
    //             if ($team->unit_id) {
    //                 $unitIds->push($team->unit_id);
    //             }
    //             foreach ($team->teamBrands as $brand) {
    //                 if ($brand->unit_id) {
    //                     $unitIds->push($brand->unit_id);
    //                 }
    //             }
    //         }
    //         $unitIds = $unitIds->unique()->values();

    //         $units = \App\Models\Unit::whereIn('id', $unitIds)
    //             ->select('id', 'title')
    //             ->get();

    //         // Format teams with team members
    //         $formattedTeams = $teams->map(function ($team) {
    //             return [
    //                 'id' => $team->id,
    //                 'title' => $team->title,
    //                 'description' => $team->description,
    //                 'unit_id' => $team->unit_id,
    //                 'unit' => $team->unit ? [
    //                     'id' => $team->unit->id,
    //                     'title' => $team->unit->title
    //                 ] : null,
    //                 'brands' => $team->teamBrands->map(function ($brand) {
    //                     return [
    //                         'id' => $brand->id,
    //                         'title' => $brand->title,
    //                         'domain' => $brand->domain,
    //                         'unit_id' => $brand->unit_id,
    //                     ];
    //                 }),
    //                 'team_members' => $team->user->map(function ($member) {
    //                     return [
    //                         'id' => $member->id,
    //                         'name' => $member->name,
    //                         'email' => $member->email,
    //                         'photo' => $member->photo,
    //                         'status' => $member->status,
    //                     ];
    //                 }),
    //             ];
    //         });

    //         return response()->json([
    //             'message' => 'Profile fetched successfully',
    //             'user' => [
    //                 'id' => $user->id,
    //                 'name' => $user->name,
    //                 'email' => $user->email,
    //                 'photo' => $user->photo,
    //                 'phone' => $user->phone,
    //                 'status' => $user->status,
    //                 'roles' => $user->getRoleNames(),
    //                 'permissions' => $allPermissions,
    //             ],
    //             'teams' => $formattedTeams,
    //             'brands' => $brands->map(function ($brand) {
    //                 return [
    //                     'id' => $brand->id,
    //                     'title' => $brand->title,
    //                     'domain' => $brand->domain,
    //                     'unit_id' => $brand->unit_id,
    //                     'unit' => $brand->unit ? [
    //                         'id' => $brand->unit->id,
    //                         'title' => $brand->unit->title
    //                     ] : null,
    //                 ];
    //             }),
    //             'units' => $units,
    //         ], 200);
    //     } catch (Exception $e) {
    //         return errorResponse($e);
    //     }
    // }
}
