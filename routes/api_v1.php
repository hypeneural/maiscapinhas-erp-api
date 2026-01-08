<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\StoreController as AdminStoreController;
use App\Http\Controllers\Api\V1\Admin\StoreUserController as AdminStoreUserController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Http\Controllers\Api\V1\BonusRuleController;
use App\Http\Controllers\Api\V1\CashClosingController;
use App\Http\Controllers\Api\V1\CashShiftController;
use App\Http\Controllers\Api\V1\CommissionRuleController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MonthlyGoalController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PeopleAnalyticsController;
use App\Http\Controllers\Api\V1\RankingController;
use App\Http\Controllers\Api\V1\ReportController;
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

// Auth routes (public)
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->name('reset-password');
});

// ============================================
// Protected Routes (authentication required)
// ============================================

Route::middleware('auth:sanctum')->group(function () {

    // Auth (logout requires auth)
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        Route::put('/password', [PasswordResetController::class, 'changePassword'])->name('change-password');
    });

    // Me (current user profile)
    Route::get('/me', MeController::class)->name('me');

    // Stores (user's stores)
    Route::prefix('stores')->name('stores.')->group(function () {
        Route::get('/', [StoreController::class, 'index'])->name('index');
        Route::get('/{store}', [StoreController::class, 'show'])->name('show');
        Route::get('/{store}/sellers', [StoreController::class, 'sellers'])->name('sellers');
        Route::put('/{store}/photo', [StoreController::class, 'updatePhoto'])->name('photo');
    });

    // Users (avatar upload)
    Route::prefix('users')->name('users.')->group(function () {
        Route::put('/{user}/avatar', [AvatarController::class, 'updateAvatar'])->name('avatar');
    });

    // Sales (CRUD)
    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('/', [SaleController::class, 'index'])->name('index');
        Route::post('/', [SaleController::class, 'store'])->name('store');
        Route::get('/{sale}', [SaleController::class, 'show'])->name('show');
        Route::put('/{sale}', [SaleController::class, 'update'])->name('update');
        Route::delete('/{sale}', [SaleController::class, 'destroy'])->name('destroy');
    });

    // Cash Management
    Route::prefix('cash')->name('cash.')->group(function () {
        // Shifts
        Route::prefix('shifts')->name('shifts.')->group(function () {
            Route::get('/', [CashShiftController::class, 'index'])->name('index');
            Route::get('/pending', [CashShiftController::class, 'pending'])->name('pending');
            Route::get('/divergent', [CashShiftController::class, 'divergent'])->name('divergent');
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

    // ============================================
    // Rules (CRUD - admin/gerente only)
    // ============================================
    Route::prefix('rules')->name('rules.')->group(function () {
        Route::apiResource('bonus', BonusRuleController::class)->parameters(['bonus' => 'rule']);
        Route::apiResource('commission', CommissionRuleController::class)->parameters(['commission' => 'rule']);
    });

    // ============================================
    // Goals (CRUD - admin/gerente only)
    // ============================================
    Route::prefix('goals')->name('goals.')->group(function () {
        Route::get('/monthly', [MonthlyGoalController::class, 'index'])->name('monthly.index');
        Route::post('/monthly', [MonthlyGoalController::class, 'store'])->name('monthly.store');
        Route::get('/monthly/{goal}', [MonthlyGoalController::class, 'show'])->name('monthly.show');
        Route::put('/monthly/{goal}', [MonthlyGoalController::class, 'update'])->name('monthly.update');
        Route::delete('/monthly/{goal}', [MonthlyGoalController::class, 'destroy'])->name('monthly.destroy');
        Route::put('/monthly/{goal}/splits', [MonthlyGoalController::class, 'setSplits'])->name('monthly.splits');
    });

    // ============================================
    // Finance Ledger (extrato)
    // ============================================
    Route::prefix('finance')->name('finance.')->group(function () {
        Route::get('/bonus', [FinanceController::class, 'bonus'])->name('bonus');
        Route::get('/bonus/calculate', [FinanceController::class, 'calculateBonus'])->name('bonus.calculate');
        Route::get('/bonus/seller/{seller}', [FinanceController::class, 'sellerBonus'])->name('bonus.seller');
        Route::get('/commission', [FinanceController::class, 'commission'])->name('commission');
        Route::get('/commission/seller/{seller}', [FinanceController::class, 'sellerCommission'])->name('commission.seller');
        Route::get('/commission/projection/{seller}', [FinanceController::class, 'commissionProjection'])->name('commission.projection');
    });

    // ============================================
    // Analytics
    // ============================================
    Route::prefix('analytics')->name('analytics.')->group(function () {
        Route::get('/people/shift', [PeopleAnalyticsController::class, 'shift'])->name('people.shift');
        Route::post('/people/shift', [PeopleAnalyticsController::class, 'store'])->name('people.shift.store');
    });

    // ============================================
    // Dashboards
    // ============================================
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::get('/vendedor', [DashboardController::class, 'vendedor'])->name('vendedor');
        Route::get('/conferente', [DashboardController::class, 'conferente'])->name('conferente');
        Route::get('/admin', [DashboardController::class, 'admin'])->name('admin');
    });

    // ============================================
    // Reports (gerencial)
    // ============================================
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/store-performance', [ReportController::class, 'storePerformance'])->name('store-performance');
        Route::get('/consolidated', [ReportController::class, 'consolidatedPerformance'])->name('consolidated');
        Route::get('/cash-integrity', [ReportController::class, 'cashIntegrity'])->name('cash-integrity');
        Route::get('/ranking', [RankingController::class, 'index'])->name('ranking');
    });

    // ============================================
    // Users (public endpoints)
    // ============================================
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/birthdays', [RankingController::class, 'birthdays'])->name('birthdays');
    });


    // ============================================
    // Admin Routes (admin only)
    // ============================================
    Route::prefix('admin')->name('admin.')->group(function () {

        // Users Management
        Route::apiResource('users', AdminUserController::class);

        // Stores Management
        Route::apiResource('stores', AdminStoreController::class);

        // Store-User Bindings
        Route::prefix('stores/{store}/users')->name('stores.users.')->group(function () {
            Route::get('/', [AdminStoreUserController::class, 'index'])->name('index');
            Route::post('/', [AdminStoreUserController::class, 'store'])->name('store');
            Route::put('/{user}', [AdminStoreUserController::class, 'update'])->name('update');
            Route::delete('/{user}', [AdminStoreUserController::class, 'destroy'])->name('destroy');
        });

        // Audit Logs
        Route::prefix('audit-logs')->name('audit-logs.')->group(function () {
            Route::get('/', [AuditLogController::class, 'index'])->name('index');
            Route::get('/stats', [AuditLogController::class, 'stats'])->name('stats');
            Route::get('/{auditLog}', [AuditLogController::class, 'show'])->name('show');
        });
    });

});
