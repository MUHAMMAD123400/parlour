<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyInvoice;
use App\Models\Plan;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CompanyInvoiceController extends Controller
{
    /**
     * Display a listing of the company invoices with filters & KPI totals.
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

            $perPage = $request->per_page ?? 10;

            $query = CompanyInvoice::with([
                'company:id,company_name,company_phone,company_email,company_logo',
                'plan:id,name,monthly,quarterly,yearly',
            ]);

            // ── Search (Invoice Number, Company Name, Plan Name, Phone) ──
            if ($request->filled('search')) {
                $s = trim($request->search);
                $query->where(function ($q) use ($s) {
                    $q->where('invoice_number', 'like', "%{$s}%")
                        ->orWhereHas('company', function ($cq) use ($s) {
                            $cq->where('company_name', 'like', "%{$s}%")
                                ->orWhere('company_phone', 'like', "%{$s}%")
                                ->orWhere('company_email', 'like', "%{$s}%");
                        })
                        ->orWhereHas('plan', function ($pq) use ($s) {
                            $pq->where('name', 'like', "%{$s}%");
                        });
                });
            }

            // ── Filters: Company ─────────────────────────────────────────
            if ($request->filled('company_id')) {
                $companyIds = is_array($request->company_id)
                    ? $request->company_id
                    : explode(',', $request->company_id);
                $query->whereIn('company_id', $companyIds);
            }

            // ── Filters: Plan ────────────────────────────────────────────
            if ($request->filled('plan_id')) {
                $planIds = is_array($request->plan_id)
                    ? $request->plan_id
                    : explode(',', $request->plan_id);
                $query->whereIn('plan_id', $planIds);
            }

            // ── Filters: Billing Cycle ───────────────────────────────────
            if ($request->filled('billing_cycle')) {
                $cycles = is_array($request->billing_cycle)
                    ? $request->billing_cycle
                    : explode(',', $request->billing_cycle);
                $cycles = array_map('strtolower', array_map('trim', $cycles));
                $query->whereIn('billing_cycle', $cycles);
            }

            // ── Filters: Payment Status ──────────────────────────────────
            if ($request->filled('payment_status')) {
                $statuses = is_array($request->payment_status)
                    ? $request->payment_status
                    : explode(',', $request->payment_status);
                $statuses = array_map('strtolower', array_map('trim', $statuses));
                $query->whereIn('payment_status', $statuses);
            }

            // ── Filters: Invoice Date Range ──────────────────────────────
            $fromDate = $request->input('from_date', $request->input('start_date', $request->input('date_from')));
            $toDate   = $request->input('to_date', $request->input('end_date', $request->input('date_to')));

            if ($fromDate) {
                $query->whereDate('date', '>=', Carbon::parse($fromDate)->format('Y-m-d'));
            }
            if ($toDate) {
                $query->whereDate('date', '<=', Carbon::parse($toDate)->format('Y-m-d'));
            }

            // ── Filters: Total Amount Range ──────────────────────────────
            if ($request->filled('min_amount')) {
                $query->where('total_amount', '>=', (float) $request->min_amount);
            }
            if ($request->filled('max_amount')) {
                $query->where('total_amount', '<=', (float) $request->max_amount);
            }

            // ── Sorting ──────────────────────────────────────────────────
            $sortBy = $request->input('sort_by', 'id');
            $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

            if (in_array($sortBy, ['id', 'date', 'amount', 'total_amount', 'created_at', 'invoice_number'])) {
                $query->orderBy($sortBy, $sortDir);
            } else {
                $query->latest('id');
            }

            $invoices = $query->paginate($perPage);

            // ── KPI Summary Totals ───────────────────────────────────────
            $totals = [
                'total_invoices' => CompanyInvoice::count(),
                'paid'           => CompanyInvoice::where('payment_status', 'paid')->count(),
                'unpaid'         => CompanyInvoice::whereIn('payment_status', ['pending', 'overdue'])->count(),
                'collected'      => (float) CompanyInvoice::where('payment_status', 'paid')->sum('total_amount'),
            ];

            return \Helper::paginatedResponse($invoices, $totals);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Store a newly created company invoice.
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
                'company_id'     => 'required|integer|exists:companies,id',
                'plan_id'        => 'required|integer|exists:plans,id',
                'billing_cycle'  => 'required|string|in:monthly,quarterly,yearly,Monthly,Quarterly,Yearly',
                'payment_status' => 'nullable|string|in:pending,paid,overdue,cancelled,Pending,Paid,Overdue,Cancelled',
                'date'           => 'required|date',
                'discount'       => 'nullable|numeric|min:0',
                'amount'         => 'nullable|numeric|min:0',
                'notes'          => 'nullable|string',
            ]);

            $plan = Plan::findOrFail($validated['plan_id']);
            $billingCycle = strtolower($validated['billing_cycle']);
            $paymentStatus = strtolower($validated['payment_status'] ?? 'pending');

            // Determine base amount
            if (isset($validated['amount']) && (float) $validated['amount'] > 0) {
                $amount = (float) $validated['amount'];
            } else {
                $amount = match ($billingCycle) {
                    'monthly'   => (float) $plan->monthly,
                    'quarterly' => (float) $plan->quarterly,
                    'yearly'    => (float) $plan->yearly,
                    default     => (float) $plan->monthly,
                };
            }

            $discount = (float) ($validated['discount'] ?? 0);
            $totalAmount = max(0, $amount - $discount);

            $invoice = CompanyInvoice::create([
                'company_id'     => $validated['company_id'],
                'plan_id'        => $validated['plan_id'],
                'billing_cycle'  => $billingCycle,
                'payment_status' => $paymentStatus,
                'date'           => Carbon::parse($validated['date'])->format('Y-m-d'),
                'amount'         => $amount,
                'discount'       => $discount,
                'total_amount'   => $totalAmount,
                'notes'          => $validated['notes'] ?? null,
            ]);

            $invoice->load([
                'company:id,company_name,company_phone,company_email,company_logo',
                'plan:id,name,monthly,quarterly,yearly',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice created successfully.',
                'data'    => $invoice,
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Display the specified company invoice.
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();

            if (! $user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error'   => 'forbidden',
                ], 403);
            }

            $invoice = CompanyInvoice::with([
                'company:id,company_name,company_phone,company_email,company_logo,company_address,company_city,company_state',
                'plan:id,name,monthly,quarterly,yearly',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $invoice,
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e, 404);
        }
    }

    /**
     * Update the specified company invoice.
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

            $invoice = CompanyInvoice::findOrFail($id);

            $validated = $request->validate([
                'company_id'     => 'sometimes|required|integer|exists:companies,id',
                'plan_id'        => 'sometimes|required|integer|exists:plans,id',
                'billing_cycle'  => 'sometimes|required|string|in:monthly,quarterly,yearly,Monthly,Quarterly,Yearly',
                'payment_status' => 'sometimes|required|string|in:pending,paid,overdue,cancelled,Pending,Paid,Overdue,Cancelled',
                'date'           => 'sometimes|required|date',
                'discount'       => 'nullable|numeric|min:0',
                'amount'         => 'nullable|numeric|min:0',
                'notes'          => 'nullable|string',
            ]);

            $companyId    = $validated['company_id'] ?? $invoice->company_id;
            $planId       = $validated['plan_id'] ?? $invoice->plan_id;
            $billingCycle = isset($validated['billing_cycle']) ? strtolower($validated['billing_cycle']) : $invoice->billing_cycle;
            $paymentStatus = isset($validated['payment_status']) ? strtolower($validated['payment_status']) : $invoice->payment_status;
            $date         = isset($validated['date']) ? Carbon::parse($validated['date'])->format('Y-m-d') : $invoice->date;

            // Recalculate amount if plan, cycle or amount provided
            if (isset($validated['amount'])) {
                $amount = (float) $validated['amount'];
            } elseif (isset($validated['plan_id']) || isset($validated['billing_cycle'])) {
                $plan = Plan::findOrFail($planId);
                $amount = match ($billingCycle) {
                    'monthly'   => (float) $plan->monthly,
                    'quarterly' => (float) $plan->quarterly,
                    'yearly'    => (float) $plan->yearly,
                    default     => (float) $plan->monthly,
                };
            } else {
                $amount = (float) $invoice->amount;
            }

            $discount = isset($validated['discount']) ? (float) $validated['discount'] : (float) $invoice->discount;
            $totalAmount = max(0, $amount - $discount);

            $invoice->update([
                'company_id'     => $companyId,
                'plan_id'        => $planId,
                'billing_cycle'  => $billingCycle,
                'payment_status' => $paymentStatus,
                'date'           => $date,
                'amount'         => $amount,
                'discount'       => $discount,
                'total_amount'   => $totalAmount,
                'notes'          => array_key_exists('notes', $validated) ? $validated['notes'] : $invoice->notes,
            ]);

            $invoice->load([
                'company:id,company_name,company_phone,company_email,company_logo',
                'plan:id,name,monthly,quarterly,yearly',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice updated successfully.',
                'data'    => $invoice->fresh(['company', 'plan']),
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Remove the specified company invoice.
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

            $invoice = CompanyInvoice::findOrFail($id);
            $invoice->delete();

            return response()->json([
                'success' => true,
                'message' => 'Invoice deleted successfully.',
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Update payment status of an invoice quickly.
     */
    public function updatePaymentStatus(Request $request, $id)
    {
        try {
            if (! $request->user()->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error'   => 'forbidden',
                ], 403);
            }

            $validated = $request->validate([
                'payment_status' => 'required|string|in:pending,paid,overdue,cancelled,Pending,Paid,Overdue,Cancelled',
            ]);

            $invoice = CompanyInvoice::findOrFail($id);
            $invoice->payment_status = strtolower($validated['payment_status']);
            $invoice->save();

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated successfully.',
                'data'    => $invoice->fresh(['company', 'plan']),
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
