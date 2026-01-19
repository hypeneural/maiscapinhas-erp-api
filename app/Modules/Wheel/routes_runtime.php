<?php

declare(strict_types=1);

/**
 * Rotas Runtime do Módulo Wheel (Roleta nas TVs)
 * 
 * Estas rotas são usadas pela TV e Mobile em tempo real.
 * Não requerem autenticação admin, usam tokens próprios.
 */

use App\Http\Controllers\Api\V1\Wheel\MobileRuntimeController;
use App\Http\Controllers\Api\V1\Wheel\RealtimeAuthController;
use App\Http\Controllers\Api\V1\Wheel\ScreenRuntimeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| TV (Screen) Endpoints
|--------------------------------------------------------------------------
|
| Autenticação via screen secret_token no header Authorization: Bearer {token}
|
*/

Route::prefix('screens/{screenKey}')->name('screens.')->group(function () {
    // Autenticação da TV (body tem secret_token)
    Route::post('/auth', [ScreenRuntimeController::class, 'auth'])
        ->name('auth');

    // Criar sessão (QR Code)
    Route::post('/sessions', [ScreenRuntimeController::class, 'createSession'])
        ->name('sessions.create');

    // Estado atual
    Route::get('/state', [ScreenRuntimeController::class, 'state'])
        ->name('state');

    // Heartbeat
    Route::post('/heartbeat', [ScreenRuntimeController::class, 'heartbeat'])
        ->name('heartbeat');
});

// Cancelar sessão
Route::delete('/sessions/{sessionKey}', [ScreenRuntimeController::class, 'expireSession'])
    ->name('sessions.expire');

/*
|--------------------------------------------------------------------------
| Mobile (Player) Endpoints
|--------------------------------------------------------------------------
|
| Após join, autenticação via player access_token no header Authorization: Bearer {token}
|
*/

Route::prefix('sessions/{sessionKey}')->name('sessions.')->group(function () {
    // Entrar na fila (não precisa de token, só phone)
    Route::post('/join', [MobileRuntimeController::class, 'join'])
        ->name('join');

    // Solicitar código de verificação
    Route::post('/request-code', [MobileRuntimeController::class, 'requestCode'])
        ->name('request-code');

    // Verificar código
    Route::post('/verify', [MobileRuntimeController::class, 'verify'])
        ->name('verify');
});

Route::prefix('mobile')->name('mobile.')->group(function () {
    // Solicitar giro
    Route::post('/spins', [MobileRuntimeController::class, 'spin'])
        ->name('spins.create');

    // Estado do player
    Route::get('/state', [MobileRuntimeController::class, 'state'])
        ->name('state');

    // Atualizar endereço (ViaCEP)
    Route::post('/address', [MobileRuntimeController::class, 'updateAddress'])
        ->name('address.update');
});

// ACK do giro
Route::post('/spins/{spinKey}/ack', [MobileRuntimeController::class, 'ackSpin'])
    ->name('spins.ack');

/*
|--------------------------------------------------------------------------
| Realtime (Ably) Auth
|--------------------------------------------------------------------------
|
| Endpoint para TV e Mobile obterem token Ably.
|
*/

Route::post('/realtime/auth', [RealtimeAuthController::class, 'auth'])
    ->name('realtime.auth');
