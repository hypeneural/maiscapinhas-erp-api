<?php

/**
 * Routes for WhatsAppInstances module.
 *
 * These routes are included in api_v1.php under the prefix 'whatsapp'.
 * Base path: /api/v1/whatsapp
 */

use App\Http\Controllers\Api\V1\Admin\WhatsAppInstanceController;
use App\Http\Controllers\Api\V1\WhatsAppMessageController;
use Illuminate\Support\Facades\Route;

// ============================================
// Instance Administration
// ============================================
Route::prefix('instances')->name('instances.')->group(function () {
    // CRUD
    Route::get('/', [WhatsAppInstanceController::class, 'index'])
        ->name('index')
        ->middleware('permission:whatsapp.instances.view');

    Route::post('/', [WhatsAppInstanceController::class, 'store'])
        ->name('store')
        ->middleware('permission:whatsapp.instances.create');

    Route::get('/{instance}', [WhatsAppInstanceController::class, 'show'])
        ->name('show')
        ->middleware('permission:whatsapp.instances.view');

    Route::match(['put', 'patch'], '/{instance}', [WhatsAppInstanceController::class, 'update'])
        ->name('update')
        ->middleware('permission:whatsapp.instances.update');

    Route::delete('/{instance}', [WhatsAppInstanceController::class, 'destroy'])
        ->name('destroy')
        ->middleware('permission:whatsapp.instances.delete');

    // Instance Actions
    Route::post('/{instance}/set-default', [WhatsAppInstanceController::class, 'setDefault'])
        ->name('set-default')
        ->middleware('permission:whatsapp.instances.update');

    // Secrets Management
    Route::delete('/{instance}/secrets/api-key', [WhatsAppInstanceController::class, 'clearApiKey'])
        ->name('clear-api-key')
        ->middleware('permission:whatsapp.instances.manage-secrets');

    Route::delete('/{instance}/secrets/token', [WhatsAppInstanceController::class, 'clearToken'])
        ->name('clear-token')
        ->middleware('permission:whatsapp.instances.manage-secrets');

    // Connection & State
    Route::get('/{instance}/state', [WhatsAppInstanceController::class, 'state'])
        ->name('state')
        ->middleware('permission:whatsapp.instances.view');

    Route::get('/{instance}/connect', [WhatsAppInstanceController::class, 'connect'])
        ->name('connect')
        ->middleware('permission:whatsapp.instances.connect');

    Route::post('/{instance}/test', [WhatsAppInstanceController::class, 'test'])
        ->name('test')
        ->middleware('permission:whatsapp.instances.connect');
});

// ============================================
// Messaging
// ============================================
Route::prefix('messages')->name('messages.')->group(function () {
    Route::post('/{instance}/text', [WhatsAppMessageController::class, 'sendText'])
        ->name('text')
        ->middleware('permission:whatsapp.messages.send');

    Route::post('/{instance}/media', [WhatsAppMessageController::class, 'sendMedia'])
        ->name('media')
        ->middleware('permission:whatsapp.messages.send');
});

// ============================================
// Number Verification
// ============================================
Route::prefix('numbers')->name('numbers.')->group(function () {
    Route::post('/{instance}/check', [WhatsAppMessageController::class, 'checkNumbers'])
        ->name('check')
        ->middleware('permission:whatsapp.numbers.check');
});
