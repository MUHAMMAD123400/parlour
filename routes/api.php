<?php

use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\BillingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::controller(LoginController::class)->group(function () {
    Route::post('/login', 'login');
});

Route::post('/logout', [LoginController::class, 'logout']);

Route::middleware('auth:sanctum')->group(function () {
    Route::controller(UserController::class)->prefix('/users')->group(function () {
        Route::get('/', 'index');
        Route::post('store', 'store');
        Route::get('/{id}/show', 'show');
        Route::post('/{id}/update', 'update');
        Route::delete('/{id}/delete', 'destroy');

        // Sync user roles (replaces existing roles)
        Route::post('/{id}/update-role', 'updateRole');
    });

    Route::controller(RoleController::class)->prefix('/roles')->group(function () {
        Route::get('/', 'index');
        Route::post('store', 'store');
        Route::get('/{id}/show', 'show');
        Route::post('/{id}/update', 'update');
        Route::delete('/{id}/delete', 'destroy');

        // Sync role permissions (replaces existing permissions)
        Route::post('/{id}/assign-permissions', 'assignPermissions');
    });

    Route::controller(PermissionController::class)->prefix('/permissions')->group(function () {
        Route::get('/', 'index');
        Route::get('/all', 'fetchAll');
        Route::post('store', 'store');
        Route::get('/{id}/show', 'show');
        Route::post('/{id}/update', 'update');
        Route::delete('/{id}/delete', 'destroy');
    });

    Route::controller(CategoryController::class)->prefix('/categories')->group(function () {
        Route::get('/', 'index');
        Route::post('store', 'store');
        Route::get('/{id}/show', 'show');
        Route::post('/{id}/update', 'update');
        Route::delete('/{id}/delete', 'destroy');
    });


    Route::controller(CustomerController::class)->prefix('/customers')->group(function () {
        Route::get('/', 'index');
        Route::post('store', 'store');
        Route::get('/{id}/show', 'show');
        Route::get('/{id}/visit-history', 'visitHistory');
        Route::get('/{id}/spending-analysis', 'spendingAnalysis');
        Route::post('/{id}/update', 'update');
        Route::delete('/{id}/delete', 'destroy');
    });

    Route::controller(ProductController::class)->prefix('/products')->group(function () {
        Route::get('/', 'index');
        Route::post('store', 'store');
        Route::get('/{id}/show', 'show');
        Route::post('/{id}/update', 'update');
        Route::delete('/{id}/delete', 'destroy');
    });

    Route::controller(ServiceController::class)->prefix('/services')->group(function () {
        Route::get('/', 'index');
        Route::post('store', 'store');
        Route::get('/{id}/show', 'show');
        Route::post('/{id}/update', 'update');
        Route::delete('/{id}/delete', 'destroy');
    });

    Route::controller(DiscountController::class)->prefix('/discounts')->group(function () {
        Route::get('/settings', 'getSettings');
        Route::post('/settings', 'updateSettings');
        Route::get('/', 'index');
        Route::post('store', 'store');
        Route::get('/{id}/show', 'show');
        Route::post('/{id}/update', 'update');
        Route::delete('/{id}/delete', 'destroy');
    });

    Route::controller(BillingController::class)->prefix('/bills')->group(function () {
        Route::get('/', 'index');
        Route::post('store', 'store');
        Route::get('/{id}/show', 'show');
        Route::delete('/{id}/delete', 'destroy');
    });
});
