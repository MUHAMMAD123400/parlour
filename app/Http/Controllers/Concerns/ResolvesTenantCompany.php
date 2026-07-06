<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;

trait ResolvesTenantCompany
{
    protected function resolveAuthenticatedCompanyId(User $user): int
    {
        if (! $user->company_id) {
            abort(response()->json(
                [
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ],
                403
            ));
        }

        return (int) $user->company_id;
    }

    /**
     * Resolve company ID for report endpoints.
     *
     * Super admin: must pass company_id (they have no own company).
     * Normal user: always uses their own company_id, ignores any passed value.
     */
    protected function resolveReportCompanyId(User $user, ?int $companyId): int
    {
        if ($user->hasRole('super_admin')) {
            if (! $companyId) {
                abort(response()->json(
                    [
                        'message' => 'company_id is required for super admin.',
                        'error'   => 'validation_error',
                    ],
                    422
                ));
            }

            return (int) $companyId;
        }

        return $this->resolveAuthenticatedCompanyId($user);
    }
}
