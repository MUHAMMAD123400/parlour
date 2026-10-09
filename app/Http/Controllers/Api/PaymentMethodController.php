<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentMethodController extends Controller
{
    /**
     * Super Admin - Display a listing of all payment methods with search, filter, and pagination.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            if (! $user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error'   => 'forbidden',
                ], 403);
            }

            $perPage = $request->input('per_page', 10);
            $query = PaymentMethod::query();

            // Search query
            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('bank_name', 'like', "%{$search}%")
                        ->orWhere('account_title', 'like', "%{$search}%")
                        ->orWhere('account_number', 'like', "%{$search}%")
                        ->orWhere('iban', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%");
                });
            }

            // Filter by type
            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            // Filter by status (0 or 1)
            if ($request->has('status') && $request->status !== null && $request->status !== '') {
                $status = (int) $request->status;
                if (in_array($status, [0, 1], true)) {
                    $query->where('status', $status);
                }
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'display_order');
            $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

            if (in_array($sortBy, ['id', 'name', 'bank_name', 'account_title', 'display_order', 'status', 'created_at'])) {
                $query->orderBy($sortBy, $sortDir);
            } else {
                $query->orderBy('display_order', 'asc')->orderBy('id', 'asc');
            }

            // Return all or paginated
            if ($request->boolean('all')) {
                $paymentMethods = $query->get();
                return response()->json([
                    'success' => true,
                    'data'    => $paymentMethods,
                ], 200);
            }

            $paymentMethods = $query->paginate($perPage);

            $totals = [
                'total_methods'  => PaymentMethod::count(),
                'active_methods' => PaymentMethod::where('status', 1)->count(),
                'inactive_methods' => PaymentMethod::where('status', 0)->count(),
            ];

            return \Helper::paginatedResponse($paymentMethods, $totals);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Super Admin - Store a newly created payment method.
     */
    public function store(Request $request)
    {
        try {
            if (! $request->user()->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error'   => 'forbidden',
                ], 403);
            }

            $validated = $request->validate([
                'name'           => 'required|string|max:255',
                'type'           => 'nullable|string|max:100',
                'bank_name'      => 'nullable|string|max:255',
                'account_title'  => 'required|string|max:255',
                'account_number' => 'required|string|max:255',
                'iban'           => 'nullable|string|max:255',
                'branch_code'    => 'nullable|string|max:100',
                'branch_name'    => 'nullable|string|max:255',
                'swift_code'     => 'nullable|string|max:100',
                'routing_number' => 'nullable|string|max:100',
                'qr_code'        => 'nullable',
                'instructions'   => 'nullable|string',
                'display_order'  => 'nullable|integer',
                'status'         => 'nullable|in:0,1,Active,Inactive',
            ]);

            // Format status
            if (isset($validated['status'])) {
                if ($validated['status'] === 'Active' || $validated['status'] === '1' || $validated['status'] === 1) {
                    $validated['status'] = 1;
                } else {
                    $validated['status'] = 0;
                }
            } else {
                $validated['status'] = 1;
            }

            // Handle file upload for qr_code
            if ($request->hasFile('qr_code')) {
                $validated['qr_code'] = $this->uploadFile($request->file('qr_code'), 'payment_methods/qrcodes');
            }

            $paymentMethod = PaymentMethod::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Payment method created successfully.',
                'data'    => $paymentMethod,
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Super Admin - Display the specified payment method.
     */
    public function show(Request $request, $id)
    {
        try {
            if (! $request->user()->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error'   => 'forbidden',
                ], 403);
            }

            $paymentMethod = PaymentMethod::findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Payment method fetched successfully.',
                'data'    => $paymentMethod,
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e, 404);
        }
    }

    /**
     * Super Admin - Update the specified payment method.
     */
    public function update(Request $request, $id)
    {
        try {
            if (! $request->user()->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error'   => 'forbidden',
                ], 403);
            }

            $paymentMethod = PaymentMethod::findOrFail($id);

            $validated = $request->validate([
                'name'           => 'sometimes|required|string|max:255',
                'type'           => 'nullable|string|max:100',
                'bank_name'      => 'nullable|string|max:255',
                'account_title'  => 'sometimes|required|string|max:255',
                'account_number' => 'sometimes|required|string|max:255',
                'iban'           => 'nullable|string|max:255',
                'branch_code'    => 'nullable|string|max:100',
                'branch_name'    => 'nullable|string|max:255',
                'swift_code'     => 'nullable|string|max:100',
                'routing_number' => 'nullable|string|max:100',
                'qr_code'        => 'nullable',
                'instructions'   => 'nullable|string',
                'display_order'  => 'nullable|integer',
                'status'         => 'nullable|in:0,1,Active,Inactive',
            ]);

            if (isset($validated['status'])) {
                if ($validated['status'] === 'Active' || $validated['status'] === '1' || $validated['status'] === 1) {
                    $validated['status'] = 1;
                } else {
                    $validated['status'] = 0;
                }
            }

            // Handle QR Code File Update
            if ($request->hasFile('qr_code')) {
                if ($paymentMethod->qr_code && file_exists(public_path($paymentMethod->qr_code))) {
                    @unlink(public_path($paymentMethod->qr_code));
                }
                $validated['qr_code'] = $this->uploadFile($request->file('qr_code'), 'payment_methods/qrcodes');
            } elseif ($request->has('qr_code') && is_string($request->qr_code)) {
                $validated['qr_code'] = $request->qr_code;
            }

            $paymentMethod->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Payment method updated successfully.',
                'data'    => $paymentMethod->fresh(),
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Super Admin - Remove the specified payment method.
     */
    public function destroy(Request $request, $id)
    {
        try {
            if (! $request->user()->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error'   => 'forbidden',
                ], 403);
            }

            $paymentMethod = PaymentMethod::findOrFail($id);

            if ($paymentMethod->qr_code && file_exists(public_path($paymentMethod->qr_code))) {
                @unlink(public_path($paymentMethod->qr_code));
            }

            $paymentMethod->delete();

            return response()->json([
                'success' => true,
                'message' => 'Payment method deleted successfully.',
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Company End - Get all active payment methods for sending payments.
     * Accessible by company users to view bank/wallet instructions for subscription or invoice payments.
     */
    public function companyIndex(Request $request)
    {
        try {
            $query = PaymentMethod::active()->ordered();

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            $paymentMethods = $query->get();

            return response()->json([
                'success' => true,
                'message' => 'Active payment methods fetched successfully.',
                'data'    => $paymentMethods,
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Helper to handle file uploads safely.
     */
    protected function uploadFile($file, string $folder): string
    {
        $destinationPath = public_path($folder);
        if (! file_exists($destinationPath)) {
            mkdir($destinationPath, 0777, true);
        }

        $ext = $file->getClientOriginalExtension();
        $fileName = 'pm_' . time() . '_' . Str::random(8) . '.' . $ext;
        $file->move($destinationPath, $fileName);

        return $folder . '/' . $fileName;
    }
}
