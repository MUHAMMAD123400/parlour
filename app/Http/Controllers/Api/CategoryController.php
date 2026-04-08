<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Exception;

class CategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:category.index')->only(['index']);
        $this->middleware('permission:category.show')->only(['show']);
        $this->middleware('permission:category.create')->only(['store']);
        $this->middleware('permission:category.edit')->only(['update']);
        $this->middleware('permission:category.delete')->only(['destroy']);
    }

    /**
     * Display a listing of categories
     * GET /api/categories
     */
    public function index(Request $request)
    {
        try {
            $per_page = $request->per_page ?? 10;

            $query = Category::query();

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('category_name', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%')
                        ->orWhere('color', 'like', '%' . $search . '%');
                });
            }

            if ($request->has('status')) {
                $status = (string) $request->status;

                if (in_array($status, ['0', '1'], true)) {
                    $query->where('status', $status);
                }
            }

            $categories = $query->orderBy('created_at', 'desc')->paginate($per_page);

            return \Helper::paginatedResponse($categories);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Store a newly created category
     * POST /api/categories/store
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'category_name' => 'required|string|max:255|unique:categories,category_name',
                'color' => 'required|string|max:20',
                'status' => 'nullable|in:1,0',
                'description' => 'nullable|string|max:300',
            ]);

            if (!isset($validated['status'])) {
                $validated['status'] = '1';
            }

            $category = Category::create($validated);

            return response()->json([
                'message' => 'Category created successfully',
                'data' => $category,
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Display the specified category
     * GET /api/categories/{id}/show
     */
    public function show($id)
    {
        try {
            $category = Category::findOrFail($id);

            return response()->json([
                'message' => 'Category fetched successfully',
                'data' => $category,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse('Category not found', 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Update the specified category
     * POST /api/categories/{id}/update
     */
    public function update(Request $request, $id)
    {
        try {
            $category = Category::findOrFail($id);

            $validated = $request->validate([
                'category_name' => 'required|string|max:255|unique:categories,category_name,' . $id,
                'color' => 'required|string|max:20',
                'status' => 'required|in:1,0',
                'description' => 'nullable|string|max:300',
            ]);

            $category->update($validated);

            return response()->json([
                'message' => 'Category updated successfully',
                'data' => $category->fresh(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse('Category not found', 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Remove the specified category
     * DELETE /api/categories/{id}/delete
     */
    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);
            $category->delete();

            return response()->json([
                'message' => 'Category deleted successfully',
                'data' => $category,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse('Category not found', 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
