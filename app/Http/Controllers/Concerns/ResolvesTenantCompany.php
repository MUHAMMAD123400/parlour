<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

trait ResolvesTenantCompany
{
    protected function forbidGuestCompanyStaff(User $user): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        if (! $user->company_id) {
            abort(response()->json([
                'message' => 'You do not have permission to perform this action.',
                'error' => 'forbidden',
            ], 403));
        }
    }

    /**
     * Optional list filter: super_admin may narrow results to one company.
     */
    protected function optionalSuperAdminCompanyId(Request $request): ?int
    {
        if (! $request->user()->isSuperAdmin() || ! $request->filled('company_id')) {
            return null;
        }

        $request->validate([
            'company_id' => 'integer|exists:companies,id',
        ]);

        return (int) $request->input('company_id');
    }
}
