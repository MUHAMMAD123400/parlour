<?php

namespace App\Services;

use App\Models\User;
use App\Models\Customer;

class SubscriptionService
{
    /**
     * MAIN FUNCTION: Subscription Summary
     */
    public static function getCompanySubscriptionData($company)
    {
        $subscription = $company->subscription()->with('plan')->first();

        if (!$subscription) {
            return null;
        }

        $start = $subscription->start_date;
        $end   = $subscription->end_date;

        /**
         * Base query
         */
        $usersQuery = User::where('company_id', $company->id);

        /**
         * Staff / Users usage (within plan period)
         */
        $staffUsed = (clone $usersQuery)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        /**
         * Total staff ever (excluding admin logic already handled above)
         */
        $totalStaff = (clone $usersQuery)->count();

        /**
         * Customers usage (within plan period)
         */
        $customerUsed = Customer::where('company_id', $company->id)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        /**
         * Total customers ever
         */
        $totalCustomers = Customer::where('company_id', $company->id)->count();

        return [
            'plan_id' => $subscription->plan_id,
            'plan_name' => $subscription->plan?->name,
            'start_date' => $start,
            'expiry_date' => $end,
            'status' => $subscription->is_active,

            // STAFF
            'staff_limit' => $subscription->plan->staff_limit,
            'staff_used' => $staffUsed,
            'staff_remaining' => max(0, $subscription->plan->staff_limit - $staffUsed),
            'total_staff' => $totalStaff,

            // CUSTOMERS
            'customer_limit' => $subscription->plan->customer_limit,
            'customer_used' => $customerUsed,
            'customer_remaining' => max(0, $subscription->plan->customer_limit - $customerUsed),
            'total_customers' => $totalCustomers,
        ];
    }

    /**
     * CHECK: Can create staff
     */
    public static function canCreateStaff($company): bool
    {
        $data = self::getCompanySubscriptionData($company);
        if (!$data) return false;
        return $data['staff_used'] < $data['staff_limit'];
    }

    /**
     * CHECK: Can create customer
     */
    public static function canCreateCustomer($company): bool
    {
        $data = self::getCompanySubscriptionData($company);
        if (!$data) return false;
        return $data['customer_used'] < $data['customer_limit'];
    }
}
