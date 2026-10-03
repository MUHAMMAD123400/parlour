<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\DashboardService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected DashboardService $dashboardService;

    /**
     * Inject DashboardService.
     */
    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Display a comprehensive overview for the Super Admin dashboard.
     * GET /api/super-admin/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user || ! $user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                    'error' => 'forbidden',
                ], 403);
            }

            $data = $this->dashboardService->getDashboardData();

            return response()->json([
                'success' => true,
                'message' => 'Dashboard data retrieved successfully.',
                'data' => $data,
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
