<?php

use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CompanySubscriptionController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\Reports\RevenueReportController;
use App\Http\Controllers\Api\Reports\ServiceReportController;
use App\Http\Controllers\Api\Reports\CustomerReportController;
use App\Http\Controllers\Api\Reports\PaymentReportController;
use App\Http\Controllers\Api\Reports\StaffReportController;
use Illuminate\Support\Facades\Route;

Route::controller(LoginController::class)->group(function () {
    Route::post('/login', 'login');
});

Route::post('/logout', [LoginController::class, 'logout']);

Route::prefix('super-admin')->controller(LoginController::class)->group(function () {
    Route::post('/login', 'login');
    Route::post('/logout', 'logout');
});

Route::middleware('auth:sanctum')->group(function () {

    // super admin start
    Route::middleware(['super_admin'])->prefix('super-admin')->group(function () {

        Route::controller(PlanController::class)->prefix('/plans')->group(function () {
            Route::get('/', 'index');
            Route::post('/store', 'store');
            Route::get('/{id}/show', 'show');
            Route::post('/{id}/update', 'update');
            Route::delete('/{id}/delete', 'destroy');
        });

        Route::controller(ModuleController::class)->prefix('/modules')->group(function () {
            Route::get('/', 'index');
            Route::post('store', 'store');
            Route::get('/{id}/show', 'show');
            Route::post('/{id}/update', 'update');
            Route::delete('/{id}/delete', 'destroy');
        });

        Route::controller(CompanyController::class)->prefix('/companies')->group(function () {
            Route::get('/', 'index');
            Route::post('store', 'store');
            Route::get('/{id}/show', 'show');
            Route::post('/{id}/update', 'update');
            Route::delete('/{id}/delete', 'destroy');
        });

            Route::middleware('company.module:role')->group(function () {
            Route::controller(RoleController::class)->prefix('/roles')->group(function () {
                Route::get('/', 'index');
                Route::post('store', 'store');
                Route::get('/{id}/show', 'show');
                Route::post('/{id}/update', 'update');
                Route::delete('/{id}/delete', 'destroy');
                Route::post('/{id}/assign-permissions', 'assignPermissions');
            });
        });

        Route::controller(PermissionController::class)->prefix('/permissions')->group(function () {
            Route::get('/', 'index');
            Route::get('/all', 'fetchAll');
            Route::post('store', 'store');
            Route::get('/{id}/show', 'show');
            Route::post('/{id}/update', 'update');
            Route::delete('/{id}/delete', 'destroy');
        });

        Route::controller(UserController::class)->prefix('/users')->group(function () {
            Route::get('/', 'index');
            Route::post('store', 'store');
            Route::get('/{id}/show', 'show');
            Route::post('/{id}/update', 'update');
            Route::delete('/{id}/delete', 'destroy');
            Route::post('/{id}/update-role', 'updateRole');
            Route::post('/{id}/assign-permissions', 'assignPermissions');
        });

        // ── Super Admin Reports (with optional company_id) ──────────────
        Route::prefix('reports')->group(function () {
            Route::get('revenue', [RevenueReportController::class, 'revenue']);
            Route::get('services', [ServiceReportController::class, 'services']);
            Route::get('customers', [CustomerReportController::class, 'customers']);
            Route::get('payments', [PaymentReportController::class, 'payments']);
            Route::get('staff', [StaffReportController::class, 'staff']);
        });
    });
    // super admin end


    // normal user
    Route::controller(CompanySubscriptionController::class)->prefix('subscription')->group(function () {
        Route::post('subscribe', 'subscribe');
        Route::post('unsubscribe', 'unsubscribe');
        Route::get('active', 'active');
        Route::get('history', 'history');
        Route::get('plans', 'plans');
    });

    Route::middleware('company.module:user')->group(function () {
        Route::controller(UserController::class)->prefix('/users')->group(function () {
            Route::get('/', 'index');
            Route::post('store', 'store');
            Route::get('/{id}/show', 'show');
            Route::post('/{id}/update', 'update');
            Route::delete('/{id}/delete', 'destroy');
            Route::post('/{id}/update-role', 'updateRole');
            Route::post('/{id}/assign-permissions', 'assignPermissions');
        });
    });

    Route::middleware('company.module:role')->group(function () {
        Route::controller(RoleController::class)->prefix('/roles')->group(function () {
            Route::get('/', 'index');
            Route::post('store', 'store');
            Route::get('/{id}/show', 'show');
            Route::post('/{id}/update', 'update');
            Route::delete('/{id}/delete', 'destroy');
            Route::post('/{id}/assign-permissions', 'assignPermissions');
        });
    });

    Route::middleware('company.module:permission')->group(function () {
        Route::controller(PermissionController::class)->prefix('/permissions')->group(function () {
            Route::get('/', 'index');
            Route::get('/all', 'fetchAll');
        });
    });

    Route::middleware('company.module:category')->group(function () {
        Route::controller(CategoryController::class)->prefix('/categories')->group(function () {
            Route::get('/', 'index');
            Route::post('store', 'store');
            Route::get('/{id}/show', 'show');
            Route::post('/{id}/update', 'update');
            Route::delete('/{id}/delete', 'destroy');
        });
    });

    Route::middleware('company.module:customer')->group(function () {
        Route::controller(CustomerController::class)->prefix('/customers')->group(function () {
            Route::get('/', 'index');
            Route::post('store', 'store');
            Route::get('/{id}/show', 'show');
            Route::get('/{id}/visit-history', 'visitHistory');
            Route::get('/{id}/spending-analysis', 'spendingAnalysis');
            Route::post('/{id}/update', 'update');
            Route::delete('/{id}/delete', 'destroy');
        });
    });

    Route::middleware('company.module:product')->group(function () {
        Route::controller(ProductController::class)->prefix('/products')->group(function () {
            Route::get('/', 'index');
            Route::post('store', 'store');
            Route::get('/{id}/show', 'show');
            Route::post('/{id}/update', 'update');
            Route::delete('/{id}/delete', 'destroy');
        });
    });

    Route::middleware('company.module:service')->group(function () {
        Route::controller(ServiceController::class)->prefix('/services')->group(function () {
            Route::get('/', 'index');
            Route::post('store', 'store');
            Route::get('/{id}/show', 'show');
            Route::post('/{id}/update', 'update');
            Route::delete('/{id}/delete', 'destroy');
        });
    });

    Route::middleware('company.module:discount')->group(function () {
        Route::controller(DiscountController::class)->prefix('/discounts')->group(function () {
            Route::get('/settings', 'getSettings');
            Route::post('/settings', 'updateSettings');
            Route::get('/', 'index');
            Route::post('store', 'store');
            Route::get('/{id}/show', 'show');
            Route::post('/{id}/update', 'update');
            Route::delete('/{id}/delete', 'destroy');
        });
    });

    Route::controller(PlanController::class)->prefix('/plans')->group(function () {
        Route::get('/', 'index');
    });

    Route::middleware('company.module:billing')->group(function () {
        Route::controller(BillingController::class)->prefix('/bills')->group(function () {
            Route::get('/', 'index');
            Route::post('store', 'store');
            Route::get('/{id}/show', 'show');
            Route::delete('/{id}/delete', 'destroy');
        });

        // ── Reports ──────────────────────────────────────────────────────────
        Route::prefix('reports')->group(function () {
            Route::get('revenue', [RevenueReportController::class, 'revenue']);
            Route::get('services', [ServiceReportController::class, 'services']);
            Route::get('customers', [CustomerReportController::class, 'customers']);
            Route::get('payments', [PaymentReportController::class, 'payments']);
            Route::get('staff', [StaffReportController::class, 'staff']);
        });
    });
});
