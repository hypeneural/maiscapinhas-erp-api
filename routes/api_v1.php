<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashClosingController;
use App\Http\Controllers\Api\V1\CashShiftController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\VersionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
|
| All routes in this file are prefixed with /api/v1
|
*/

// ============================================
// Public Routes (no authentication required)
// ============================================

Route::get('/health', HealthController::class)->name('health');
Route::get('/version', VersionController::class)->name('version');

// Auth routes
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('login');
});

// ============================================
// Protected Routes (authentication required)
// ============================================

Route::middleware('auth:sanctum')->group(function () {

    // Auth (logout requires auth)
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
    });

    // Me (current user profile)
    Route::get('/me', MeController::class)->name('me');

    // Stores
    Route::prefix('stores')->name('stores.')->group(function () {
        Route::get('/', [StoreController::class, 'index'])->name('index');
        Route::get('/{store}', [StoreController::class, 'show'])->name('show');
    });

    // Sales
    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('/', [SaleController::class, 'index'])->name('index');
    });

    // Cash Management
    Route::prefix('cash')->name('cash.')->group(function () {
        // Shifts
        Route::prefix('shifts')->name('shifts.')->group(function () {
            Route::get('/', [CashShiftController::class, 'index'])->name('index');
            Route::post('/', [CashShiftController::class, 'store'])->name('store');
            Route::get('/{shift}', [CashShiftController::class, 'show'])->name('show');
        });

        // Closings (actions on shifts)
        Route::prefix('closings')->name('closings.')->group(function () {
            Route::get('/{shift}', [CashClosingController::class, 'show'])->name('show');
            Route::post('/{shift}/submit', [CashClosingController::class, 'submit'])->name('submit');
            Route::post('/{shift}/approve', [CashClosingController::class, 'approve'])->name('approve');
            Route::post('/{shift}/reject', [CashClosingController::class, 'reject'])->name('reject');
        });
    });

    // Dashboards
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::get('/vendedor', [DashboardController::class, 'vendedor'])->name('vendedor');
        Route::get('/conferente', [DashboardController::class, 'conferente'])->name('conferente');
        Route::get('/admin', [DashboardController::class, 'admin'])->name('admin');
    });

});
