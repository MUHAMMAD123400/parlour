<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class ProductController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:product.index')->only(['index']);
        $this->middleware('permission:product.show')->only(['show']);
        $this->middleware('permission:product.create')->only(['store']);
        $this->middleware('permission:product.edit')->only(['update']);
        $this->middleware('permission:product.delete')->only(['destroy']);
    }

    /**
     * Display a listing of products
     * GET /api/products
     */
    public function index(Request $request)
    {
        try {
            $per_page = $request->per_page ?? 10;
            
            $query = Product::with('category');

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('product_name', 'like', '%' . $search . '%')
                      ->orWhere('brand', 'like', '%' . $search . '%')
                      ->orWhere('description', 'like', '%' . $search . '%')
                      ->orWhereHas('category', function ($categoryQuery) use ($search) {
                          $categoryQuery->where('category_name', 'like', '%' . $search . '%');
                      });
                });
            }

            // Filter by category
            if ($request->filled('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            // Filter by brand
            if ($request->filled('brand')) {
                $query->where('brand', $request->brand);
            }

            // Filter by stock status
            if ($request->filled('stock_status')) {
                $stockStatus = $request->stock_status;
                
                switch ($stockStatus) {
                    case 'in_stock':
                        // Products with quantity > 0
                        $query->where('quantity_in_stock', '>', 0);
                        break;
                    
                    case 'low_stock':
                        // Products with quantity <= minimum_stock_alert, or < 5 if minimum_stock_alert is null
                        $query->where(function ($q) {
                            $q->whereColumn('quantity_in_stock', '<=', 'minimum_stock_alert')
                              ->orWhere(function ($subQ) {
                                  $subQ->whereNull('minimum_stock_alert')
                                       ->where('quantity_in_stock', '<', 5);
                              });
                        })->where('quantity_in_stock', '>', 0); // Exclude out of stock items
                        break;
                    
                    case 'out_of_stock':
                        // Products with quantity = 0
                        $query->where('quantity_in_stock', '=', 0);
                        break;
                    
                    case 'all':
                    default:
                        // Show all products (no filter)
                        break;
                }
            }

            $products = $query->orderBy('created_at', 'desc')->paginate($per_page);

            return \Helper::paginatedResponse($products);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Store a newly created product
     * POST /api/products/store
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_name' => 'required|string|max:255',
                'brand' => 'nullable|string|max:255',
                'category_id' => 'required|integer|exists:categories,id',
                'description' => 'nullable|string',
                'quantity_in_stock' => 'required|integer|min:0',
                'unit' => 'nullable|string|max:50',
                'purchase_price' => 'required|numeric|min:0',
                'selling_price' => 'nullable|numeric|min:0',
                'minimum_stock_alert' => 'nullable|integer|min:0',
                'notes' => 'nullable|string',
            ]);

            $product = Product::create($validated);

            return response()->json([
                'message' => 'Product created successfully',
                'data' => $product
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Display the specified product
     * GET /api/products/{id}/show
     */
    public function show($id)
    {
        try {
            $product = Product::findOrFail($id);
            
            return response()->json([
                'message' => 'Product fetched successfully',
                'data' => $product
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Product not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Update the specified product
     * POST /api/products/{id}/update
     */
    public function update(Request $request, $id)
    {
        try {
            $product = Product::findOrFail($id);

            $validated = $request->validate([
                'product_name' => 'required|string|max:255',
                'brand' => 'nullable|string|max:255',
                'category_id' => 'required|integer|exists:categories,id',
                'description' => 'nullable|string',
                'quantity_in_stock' => 'required|integer|min:0',
                'unit' => 'nullable|string|max:50',
                'purchase_price' => 'required|numeric|min:0',
                'selling_price' => 'nullable|numeric|min:0',
                'minimum_stock_alert' => 'nullable|integer|min:0',
                'notes' => 'nullable|string',
            ]);

            $product->update($validated);

            return response()->json([
                'message' => 'Product updated successfully',
                'data' => $product->fresh()
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Product not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Remove the specified product
     * DELETE /api/products/{id}/delete
     */
    public function destroy($id)
    {
        try {
            $product = Product::findOrFail($id);
            $product->delete();

            return response()->json([
                'message' => 'Product deleted successfully',
                'data' => $product
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Product not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
