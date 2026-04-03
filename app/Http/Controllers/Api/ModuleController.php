<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Module;
use Exception;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:module.index')->only(['index']);
        $this->middleware('permission:module.show')->only(['show']);
        $this->middleware('permission:module.create')->only(['store']);
        $this->middleware('permission:module.edit')->only(['update']);
        $this->middleware('permission:module.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->per_page ?? 10;

            $query = Module::query();

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('module_name', 'like', '%' . $search . '%')
                        ->orWhere('permission_module_key', 'like', '%' . $search . '%')
                        ->orWhere('module_description', 'like', '%' . $search . '%');
                });
            }

            if ($request->has('module_status')) {
                $status = (string) $request->module_status;
                if (in_array($status, ['0', '1'], true)) {
                    $query->where('module_status', $status);
                }
            }

            $modules = $query->orderBy('module_name')->paginate($perPage);

            return \Helper::paginatedResponse($modules);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'module_name' => 'required|string|max:255',
                'permission_module_key' => 'required|string|max:64|regex:/^[a-z0-9_]+$/|unique:modules,permission_module_key',
                'module_description' => 'nullable|string',
                'module_status' => 'nullable|in:1,0',
                'module_icon' => 'nullable|string|max:255',
            ]);

            if (! isset($validated['module_status'])) {
                $validated['module_status'] = '1';
            }

            $module = Module::create($validated);

            return response()->json([
                'message' => 'Module created successfully',
                'data' => $module,
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function show($id)
    {
        try {
            $module = Module::findOrFail($id);

            return response()->json(['message' => 'Module fetched successfully', 'data' => $module], 200);
        } catch (Exception $e) {
            return errorResponse($e, 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $module = Module::findOrFail($id);

            $validated = $request->validate([
                'module_name' => 'required|string|max:255',
                'permission_module_key' => 'required|string|max:64|regex:/^[a-z0-9_]+$/|unique:modules,permission_module_key,' . $module->id,
                'module_description' => 'nullable|string',
                'module_status' => 'required|in:1,0',
                'module_icon' => 'nullable|string|max:255',
            ]);

            $module->update($validated);

            return response()->json([
                'message' => 'Module updated successfully',
                'data' => $module->fresh(),
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    public function destroy($id)
    {
        try {
            $module = Module::findOrFail($id);
            $module->delete();

            return response()->json([
                'message' => 'Module deleted successfully',
                'data' => $module,
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
