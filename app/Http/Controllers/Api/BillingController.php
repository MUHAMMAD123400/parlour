<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Discount;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Exception;

class BillingController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:billing.index')->only(['index']);
        $this->middleware('permission:billing.show')->only(['show']);
        $this->middleware('permission:billing.create')->only(['store']);
        $this->middleware('permission:billing.delete')->only(['destroy']);
    }

    /**
     * Display a listing of bills
     * GET /api/bills
     */
    public function index(Request $request)
    {
        try {
            $per_page = $request->per_page ?? 10;
            
            $query = Bill::with(['customer', 'user', 'items.service', 'items.category']);

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('bill_number', 'like', '%' . $search . '%')
                      ->orWhereHas('customer', function ($q) use ($search) {
                          $q->where('name', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                      });
                });
            }

            // Filter by customer
            if ($request->filled('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }

            // Filter by user (staff who created)
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            // Filter by payment method
            if ($request->filled('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }

            // Filter by date range
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $bills = $query->orderBy('created_at', 'desc')->paginate($per_page);

            return \Helper::paginatedResponse($bills);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Store a newly created bill
     * POST /api/bills/store
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'customer_id' => 'required|exists:customers,id',
                'items' => 'required|array|min:1',
                'items.*.service_id' => 'required|exists:services,id',
                'items.*.quantity' => 'required|integer|min:1',
                'subtotal' => 'required|numeric|min:0',
                'discount_amount' => 'nullable|numeric|min:0',
                'discount_type' => 'nullable|in:none,percentage,fixed',
                'total' => 'required|numeric|min:0',
                'payment_method' => 'required|in:cash,card,online',
                'paid_amount' => 'required|numeric|min:0',
                'notes' => 'nullable|string',
            ]);

            // Get the logged-in user ID
            $userId = auth()->id();
            if (!$userId) {
                return errorResponse('User must be authenticated to create a bill', 401);
            }

            DB::beginTransaction();

            try {
                // Generate unique bill number
                $billNumber = $this->generateBillNumber();

                // Calculate change amount
                $changeAmount = max(0, $validated['paid_amount'] - $validated['total']);

                // Create the bill
                $bill = Bill::create([
                    'bill_number' => $billNumber,
                    'customer_id' => $validated['customer_id'],
                    'user_id' => $userId, // Logged-in user who created the bill
                    'subtotal' => $validated['subtotal'],
                    'discount_amount' => $validated['discount_amount'] ?? 0,
                    'discount_type' => $validated['discount_type'] ?? 'none',
                    'total' => $validated['total'],
                    'payment_method' => $validated['payment_method'],
                    'paid_amount' => $validated['paid_amount'],
                    'change_amount' => $changeAmount,
                    'notes' => $validated['notes'] ?? null,
                ]);

                // Create bill items
                foreach ($validated['items'] as $item) {
                    $service = Service::findOrFail($item['service_id']);
                    
                    $unitPrice = $service->price;
                    $totalPrice = $unitPrice * $item['quantity'];

                    BillItem::create([
                        'bill_id' => $bill->id,
                        'service_id' => $item['service_id'],
                        'item_name' => $service->service_name,
                        'item_type' => 'service',
                        'category_id' => $service->category_id,
                        'quantity' => $item['quantity'],
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                        'duration' => $service->duration,
                    ]);
                }

                // Update discount usage count if discount was applied
                if (isset($validated['discount_id']) && $validated['discount_id']) {
                    $discount = Discount::find($validated['discount_id']);
                    if ($discount) {
                        $discount->increment('usage_count');
                    }
                }

                DB::commit();

                // Load relationships
                $bill->load(['customer', 'user', 'items.service', 'items.category']);

                return response()->json([
                    'message' => 'Bill created successfully',
                    'data' => $bill
                ], 201);
            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Display the specified bill
     * GET /api/bills/{id}/show
     */
    public function show($id)
    {
        try {
            $bill = Bill::with(['customer', 'user', 'items.service', 'items.category'])->findOrFail($id);
            
            return response()->json([
                'message' => 'Bill fetched successfully',
                'data' => $bill
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Bill not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Remove the specified bill
     * DELETE /api/bills/{id}/delete
     */
    public function destroy($id)
    {
        try {
            $bill = Bill::findOrFail($id);
            $bill->delete(); // Bill items will be deleted automatically due to cascade

            return response()->json([
                'message' => 'Bill deleted successfully',
                'data' => $bill
            ], 200);
        } catch (ModelNotFoundException $e) {
            return errorResponse("Bill not found", 404);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Generate unique bill number
     */
    private function generateBillNumber()
    {
        $prefix = 'BILL';
        $date = now()->format('Ymd');
        
        // Get the last bill number for today
        $lastBill = Bill::whereDate('created_at', today())
            ->where('bill_number', 'like', $prefix . '-' . $date . '%')
            ->orderBy('bill_number', 'desc')
            ->first();

        if ($lastBill) {
            // Extract the sequence number and increment
            $parts = explode('-', $lastBill->bill_number);
            $sequence = isset($parts[2]) ? (int)$parts[2] + 1 : 1;
        } else {
            $sequence = 1;
        }

        return $prefix . '-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}
