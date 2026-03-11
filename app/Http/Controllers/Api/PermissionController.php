<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Exception;

class PermissionController extends Controller
{
     public function __construct()
    {
        // $this->middleware('permission:permission.index')->only(['index']);
        // $this->middleware('permission:permission.show')->only(['show']);
        // $this->middleware('permission:permission.create')->only(['create', 'store']);
        // $this->middleware('permission:permission.edit')->only(['edit', 'update']);
        // $this->middleware('permission:permission.delete')->only(['destroy']);
    }
    
    public function index(Request $request)
    {
        try {
            $per_page = $request->per_page ?? 10;

            $permissions = Permission::where('guard_name', 'api')
                ->orderBy('name','asc')
                ->when($request->filled('search'), function ($query) use ($request) {
                    $query->where(function($q) use ($request) {
                        $q->where('name', 'like', '%' . $request->search . '%')
                          ->orWhere('title', 'like', '%' . $request->search . '%')
                          ->orWhere('description', 'like', '%' . $request->search . '%')
                          ->orWhere('module', 'like', '%' . $request->search . '%');
                    });
                })
                ->when($request->filled('type'), function ($query) use ($request) {
                    $query->where('type', $request->type);
                })
                ->when($request->filled('module'), function ($query) use ($request) {
                    $query->where('module', $request->module);
                })
                ->when($request->filled('group_type'), function ($query) use ($request) {
                    $query->where('group_type', $request->group_type);
                })
                ->paginate($per_page);
            return \Helper::paginatedResponse($permissions);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function fetchAll(Request $request){
        try{
            $permissions = Permission::where('guard_name', 'api')
                ->orderBy('name','asc')
                ->select('id','name','title','type','module','group_type')
                ->get();

            return response()->json($permissions);

        } catch (Exception $e) {
            return errorResponse($e);
        }

    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:permissions,name,NULL,id,guard_name,api|max:255',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|max:255',
            'module' => 'nullable|string|max:255',
            'group_type' => 'nullable|string|max:255',
        ]);
        try {

            $permission = Permission::create([
                'name' => $validated['name'],
                'guard_name' => 'api',
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'type' => $validated['type'] ?? null,
                'module' => $validated['module'] ?? null,
                'group_type' => $validated['group_type'] ?? null,
            ]);

            return response()->json([
                'message' => 'Permission created successfully',
                'data' => $permission
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }


    public function update(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|unique:permissions,name,' . $permission->id . ',id,guard_name,api|max:255',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'type' => 'nullable|string|max:255',
                'module' => 'nullable|string|max:255',
                'group_type' => 'nullable|string|max:255',
            ]);
        try {
            $permission->update([
                'name' => $validated['name'],
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'type' => $validated['type'] ?? null,
                'module' => $validated['module'] ?? null,
                'group_type' => $validated['group_type'] ?? null,
            ]);

            return response()->json([
                'message' => 'Permission updated successfully',
                'data' => $permission
            ]);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function destroy($id)
    {
        try {
            $permission = Permission::with('roles')->findOrFail($id);

            if ($permission->roles()->count() > 0) {
                return response()->json([
                    'message' => 'Cannot delete permission assigned to roles.'
                ], 400);
            }

            $permission->delete();

            return response()->json(['message' => 'Permission deleted successfully']);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function show($id)
    {
        try {
            $permission = Permission::findOrFail($id);
            return response()->json($permission);
        } catch (Exception $e) {
            return errorResponse($e, 404);
        }
    }
}
