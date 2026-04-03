<?php

namespace App\Http\Middleware;

use App\Services\CompanyAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyHasModule
{
    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $user->company_id) {
            return response()->json([
                'message' => 'Your account is not linked to a company.',
                'error' => 'forbidden',
            ], 403);
        }

        if (! CompanyAccessService::companyHasModuleKey($user->company_id, $moduleKey)) {
            return response()->json([
                'message' => 'This feature is not enabled for your company.',
                'error' => 'module_not_enabled',
            ], 403);
        }

        return $next($request);
    }
}
