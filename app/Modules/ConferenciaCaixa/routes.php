<?php

/**
 * Routes for ConferenciaCaixa module.
 *
 * These routes are included in api_v1.php under the prefix 'cash'.
 * Base path: /api/v1/cash
 */

use App\Http\Controllers\Api\V1\CashClosingController;
use App\Http\Controllers\Api\V1\CashShiftController;
use Illuminate\Support\Facades\Route;

// ============================================
// Shifts (Turnos de Caixa)
// ============================================
Route::prefix('shifts')->name('shifts.')->group(function () {
    Route::get('/', [CashShiftController::class, 'index'])
        ->name('index')
        ->middleware('permission:caixa.view');

    Route::get('/pending', [CashShiftController::class, 'pending'])
        ->name('pending')
        ->middleware('permission:caixa.closing.approve');

    Route::get('/divergent', [CashShiftController::class, 'divergent'])
        ->name('divergent')
        ->middleware('permission:caixa.closing.approve');

    Route::post('/', [CashShiftController::class, 'store'])
        ->name('store')
        ->middleware('permission:caixa.shift.open');

    Route::get('/{shift}', [CashShiftController::class, 'show'])
        ->name('show')
        ->middleware('permission:caixa.view');
});

// ============================================
// Closings (Fechamentos de Caixa)
// ============================================
Route::prefix('closings')->name('closings.')->group(function () {
    Route::get('/{shift}', [CashClosingController::class, 'show'])
        ->name('show')
        ->middleware('permission:caixa.view');

    Route::post('/{shift}', [CashClosingController::class, 'store'])
        ->name('store')
        ->middleware('permission:caixa.closing.create');

    Route::put('/{shift}', [CashClosingController::class, 'update'])
        ->name('update')
        ->middleware('permission:caixa.closing.create');

    // Status Transitions
    Route::post('/{shift}/submit', [CashClosingController::class, 'submit'])
        ->name('submit')
        ->middleware('permission:caixa.closing.create');

    Route::post('/{shift}/approve', [CashClosingController::class, 'approve'])
        ->name('approve')
        ->middleware('permission:caixa.closing.approve');

    Route::post('/{shift}/reject', [CashClosingController::class, 'reject'])
        ->name('reject')
        ->middleware('permission:caixa.closing.reject');
});
