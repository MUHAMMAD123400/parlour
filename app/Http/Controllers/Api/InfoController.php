<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class InfoController extends Controller
{
    /**
     * Get Company Info & Subscription details for Company Admin.
     * GET /api/info
     */
    public function info(Request $request)
    {
        try {
            $user = $request->user();

            if (! $user->company_id) {
                return response()->json([
                    'message' => 'No company associated with this account.',
                    'error'   => 'not_found',
                ], 404);
            }

            $company = Company::with([
                'modules' => function ($q) {
                    $q->wherePivot('company_module_status', '1')->wherePivotNull('deleted_at');
                },
                'subscription.plan',
                'plan',
                'users' => function ($q) {
                    $q->whereHas('roles', fn ($rq) => $rq->where('name', 'company_admin'));
                }
            ])->find($user->company_id);

            if (! $company) {
                return response()->json([
                    'message' => 'Company not found.',
                    'error'   => 'not_found',
                ], 404);
            }

            // Company Admin details
            $adminUser = $company->users->first() ?? User::where('company_id', $company->id)->first();

            // Active or latest subscription
            $subscription = $company->subscription ?? $company->subscriptions()->latest()->first();
            $plan = $subscription?->plan ?? $company->plan;

            $now = Carbon::now();
            $today = $now->copy()->startOfDay();

            $startDate = $subscription?->start_date ? Carbon::parse($subscription->start_date) : null;
            $endDate = $subscription?->end_date ? Carbon::parse($subscription->end_date) : null;

            // Expiry & Days calculation
            $hasSubscription = ! empty($subscription);
            $daysLeft = null;
            $isExpired = true;
            $subscriptionStatus = 'no_subscription';
            $statusLabel = 'No Subscription';
            $expiryMessage = 'No subscription package found for this company.';

            if ($subscription && $endDate) {
                $endDay = $endDate->copy()->startOfDay();
                $daysLeft = (int) $today->diffInDays($endDay, false);

                if (! $subscription->is_active || $daysLeft < 0) {
                    $isExpired = true;
                    $subscriptionStatus = 'expired';
                    $daysAgo = abs($daysLeft);
                    $statusLabel = $daysAgo === 0 ? 'Expired Today' : "Expired ({$daysAgo} days ago)";
                    $expiryMessage = "Your subscription package expired on " . $endDate->format('d M Y') . " ({$daysAgo} days ago). Please renew to continue.";
                } elseif ($daysLeft === 0) {
                    $isExpired = false;
                    $subscriptionStatus = 'expires_today';
                    $statusLabel = 'Expires Today';
                    $expiryMessage = "Your subscription package expires today (" . $endDate->format('d M Y') . "). Please renew soon.";
                } elseif ($daysLeft <= 7) {
                    $isExpired = false;
                    $subscriptionStatus = 'expiring_soon';
                    $statusLabel = "Expiring Soon ({$daysLeft} days left)";
                    $expiryMessage = "Your subscription package is expiring in {$daysLeft} days on " . $endDate->format('d M Y') . ".";
                } else {
                    $isExpired = false;
                    $subscriptionStatus = 'active';
                    $statusLabel = "Active ({$daysLeft} days left)";
                    $expiryMessage = "Your subscription package is active and valid until " . $endDate->format('d M Y') . ".";
                }
            }

            // Usage & Limits calculation
            $staffLimit = (int) ($plan?->staff_limit ?? 0);
            $customerLimit = (int) ($plan?->customer_limit ?? 0);

            $staffUsed = User::where('company_id', $company->id)
                ->when($startDate && $endDate, fn ($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                ->count();
            $totalStaff = User::where('company_id', $company->id)->count();

            $customerUsed = Customer::where('company_id', $company->id)
                ->when($startDate && $endDate, fn ($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                ->count();
            $totalCustomers = Customer::where('company_id', $company->id)->count();

            $staffRemaining = $staffLimit > 0 ? max(0, $staffLimit - $staffUsed) : 'unlimited';
            $customerRemaining = $customerLimit > 0 ? max(0, $customerLimit - $customerUsed) : 'unlimited';

            $canCreateStaff = ! $isExpired && ($staffLimit === 0 || $staffUsed < $staffLimit);
            $canCreateCustomer = ! $isExpired && ($customerLimit === 0 || $customerUsed < $customerLimit);

            // General counts
            $statistics = [
                'total_staff'      => $totalStaff,
                'active_staff'     => User::where('company_id', $company->id)->where('status', '1')->count(),
                'total_customers'  => $totalCustomers,
                'total_services'   => Service::where('company_id', $company->id)->count(),
                'total_products'   => Product::where('company_id', $company->id)->count(),
                'total_categories' => Category::where('company_id', $company->id)->count(),
                'total_bills'      => Bill::where('company_id', $company->id)->count(),
            ];

            // Formatted modules
            $modulesList = $company->modules->map(function ($m) {
                return [
                    'id'                    => $m->id,
                    'module_name'           => $m->module_name,
                    'permission_module_key' => $m->permission_module_key,
                    'status'                => $m->pivot->company_module_status === '1' ? 'Active' : 'Inactive',
                ];
            })->values();

            // Subscription Amount
            $subscriptionAmount = 0.0;
            if ($plan && $subscription) {
                $subscriptionAmount = match ($subscription->type) {
                    'yearly'    => (float) ($plan->yearly ?? 0.0),
                    'quarterly' => (float) ($plan->quarterly ?? 0.0),
                    default     => (float) ($plan->monthly ?? 0.0),
                };
            }

            return response()->json([
                'message' => 'Company information and subscription details fetched successfully.',
                'data' => [
                    'company' => [
                        'id'                   => $company->id,
                        'organization_id'      => $company->organization_id,
                        'company_name'         => $company->company_name,
                        'company_email'        => $company->company_email,
                        'company_phone'        => $company->company_phone,
                        'company_address'      => $company->company_address,
                        'company_city'         => $company->company_city,
                        'company_state'        => $company->company_state,
                        'company_zip'          => $company->company_zip,
                        'company_country'      => $company->company_country,
                        'company_logo'         => $company->company_logo,
                        'logo_url'             => $company->company_logo ? url($company->company_logo) : null,
                        'company_website'      => $company->company_website,
                        'company_status'       => (string) $company->company_status,
                        'status_label'         => $company->company_status === '1' ? 'Active' : 'Inactive',
                        'company_notes'        => $company->company_notes,
                        'company_description'  => $company->company_description,
                        'company_type'         => $company->company_type,
                        'ntn'                  => $company->ntn,
                        'strn'                 => $company->strn,
                        'license_number'       => $company->license_number,
                        'created_at'           => $company->created_at,
                        'created_at_formatted' => $company->created_at?->format('d-M-Y, g:i a'),
                        'updated_at'           => $company->updated_at,
                        'updated_at_formatted' => $company->updated_at?->format('d-M-Y, g:i a'),
                        'admin' => $adminUser ? [
                            'id'     => $adminUser->id,
                            'name'   => $adminUser->name,
                            'email'  => $adminUser->email,
                            'status' => $adminUser->status === '1' ? 'Active' : 'Inactive',
                        ] : null,
                        'modules' => $modulesList,
                    ],
                    'subscription' => [
                        'has_subscription'      => $hasSubscription,
                        'id'                    => $subscription?->id,
                        'plan_id'               => $plan?->id,
                        'plan_name'             => $plan?->name ?? 'No Plan',
                        'plan_description'      => $plan?->description,
                        'billing_cycle'         => $subscription?->type ?? 'monthly',
                        'cycle_label'           => ucfirst($subscription?->type ?? 'monthly'),
                        'amount'                => $subscriptionAmount,
                        'payment_status'        => $subscription?->payment_status ?? 'pending',
                        'payment_status_label'  => ucfirst($subscription?->payment_status ?? 'pending'),
                        'start_date'            => $startDate?->format('Y-m-d'),
                        'start_date_formatted'  => $startDate?->format('d M Y'),
                        'end_date'              => $endDate?->format('Y-m-d'),
                        'end_date_formatted'    => $endDate?->format('d M Y'),
                        'expiry_date'           => $endDate?->format('Y-m-d'),
                        'expiry_date_formatted' => $endDate?->format('d M Y'),
                        'days_left'             => $daysLeft,
                        'is_active'             => (bool) ($subscription?->is_active ?? false),
                        'is_expired'            => $isExpired,
                        'status'                => $subscriptionStatus,
                        'status_label'          => $statusLabel,
                        'expiry_message'        => $expiryMessage,
                        'plan_pricing' => $plan ? [
                            'monthly'   => (float) $plan->monthly,
                            'quarterly' => (float) $plan->quarterly,
                            'yearly'    => (float) $plan->yearly,
                        ] : null,
                    ],
                    'usage_and_limits' => [
                        'staff_limit'         => $staffLimit,
                        'staff_used'          => $staffUsed,
                        'staff_remaining'     => $staffRemaining,
                        'total_staff'         => $totalStaff,
                        'can_create_staff'    => $canCreateStaff,
                        'customer_limit'      => $customerLimit,
                        'customer_used'       => $customerUsed,
                        'customer_remaining'  => $customerRemaining,
                        'total_customers'     => $totalCustomers,
                        'can_create_customer' => $canCreateCustomer,
                    ],
                    'statistics' => $statistics,
                ],
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
