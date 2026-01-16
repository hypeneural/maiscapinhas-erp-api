<?php

/**
 * Routes for Comunicados module.
 *
 * These routes should be included in the main api_v1.php file.
 *
 * Example:
 *   Route::prefix('comunicados')->name('comunicados.')->group(function () {
 *       require app_path('Modules/Comunicados/routes.php');
 *   });
 */

use App\Http\Controllers\Api\V1\ComunicadosController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ComunicadosController::class, 'index'])->name('index');
Route::post('/', [ComunicadosController::class, 'store'])->name('store');
Route::get('/{comunicado}', [ComunicadosController::class, 'show'])->name('show');
Route::patch('/{comunicado}', [ComunicadosController::class, 'update'])->name('update');
Route::delete('/{comunicado}', [ComunicadosController::class, 'destroy'])->name('destroy');