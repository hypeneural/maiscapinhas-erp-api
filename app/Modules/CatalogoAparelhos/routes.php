<?php

/**
 * Routes for CatalogoAparelhos module.
 *
 * These routes are included in api_v1.php under the prefix 'phone-catalog'.
 * Base path: /api/v1/phone-catalog
 */

use App\Http\Controllers\Api\V1\PhoneBrandController;
use App\Http\Controllers\Api\V1\PhoneModelController;
use Illuminate\Support\Facades\Route;

// ============================================
// Phone Brands
// ============================================
Route::prefix('brands')->name('brands.')->group(function () {
    Route::get('/', [PhoneBrandController::class, 'index'])
        ->name('index')
        ->middleware('permission:phone_catalog.view');
    Route::post('/', [PhoneBrandController::class, 'store'])
        ->name('store')
        ->middleware('permission:phone_catalog.create');
    Route::get('/{phoneBrand}', [PhoneBrandController::class, 'show'])
        ->name('show')
        ->middleware('permission:phone_catalog.view');
    Route::match(['put', 'patch'], '/{phoneBrand}', [PhoneBrandController::class, 'update'])
        ->name('update')
        ->middleware('permission:phone_catalog.update');
    Route::delete('/{phoneBrand}', [PhoneBrandController::class, 'destroy'])
        ->name('destroy')
        ->middleware('permission:phone_catalog.delete');
});

// ============================================
// Phone Models
// ============================================
Route::prefix('models')->name('models.')->group(function () {
    Route::get('/', [PhoneModelController::class, 'index'])
        ->name('index')
        ->middleware('permission:phone_catalog.view');
    Route::post('/', [PhoneModelController::class, 'store'])
        ->name('store')
        ->middleware('permission:phone_catalog.create');
    Route::get('/{phoneModel}', [PhoneModelController::class, 'show'])
        ->name('show')
        ->middleware('permission:phone_catalog.view');
    Route::match(['put', 'patch'], '/{phoneModel}', [PhoneModelController::class, 'update'])
        ->name('update')
        ->middleware('permission:phone_catalog.update');
    Route::delete('/{phoneModel}', [PhoneModelController::class, 'destroy'])
        ->name('destroy')
        ->middleware('permission:phone_catalog.delete');
});
