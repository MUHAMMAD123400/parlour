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
}
