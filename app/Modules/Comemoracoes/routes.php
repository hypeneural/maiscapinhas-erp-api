<?php

/**
 * Routes for Comemoracoes module.
 *
 * Base path: /api/v1/celebrations
 */

use App\Http\Controllers\Api\V1\CelebrationController;
use Illuminate\Support\Facades\Route;

Route::get('/month', [CelebrationController::class, 'month'])
    ->name('month')
    ->middleware('permission:celebrations.view');

Route::get('/upcoming', [CelebrationController::class, 'upcoming'])
    ->name('upcoming')
    ->middleware('permission:celebrations.view');

Route::get('/today', [CelebrationController::class, 'today'])
    ->name('today')
    ->middleware('permission:celebrations.view');
