<?php

declare(strict_types=1);

/**
 * Routes for Wheel module (Roleta nas TVs).
 *
 * These routes are included in api_v1.php under the prefix 'admin/wheel'.
 * Base path: /api/v1/admin/wheel
 * 
 * Access: Super Admin only
 */

use App\Http\Controllers\Api\V1\Admin\Wheel\ScreenController;
use App\Http\Controllers\Api\V1\Admin\Wheel\CampaignController;
use App\Http\Controllers\Api\V1\Admin\Wheel\PrizeController;
use App\Http\Controllers\Api\V1\Admin\Wheel\SegmentController;
use App\Http\Controllers\Api\V1\Admin\Wheel\InventoryController;
use App\Http\Controllers\Api\V1\Admin\Wheel\EventController;
use App\Http\Controllers\Api\V1\Admin\Wheel\AnalyticsController;
use Illuminate\Support\Facades\Route;

// ============================================
// Screens (TVs/Totens)
// ============================================
Route::prefix('screens')->name('screens.')->group(function () {
    Route::get('/', [ScreenController::class, 'index'])
        ->name('index')
        ->middleware('permission:wheel.screens.view');

    Route::post('/', [ScreenController::class, 'store'])
        ->name('store')
        ->middleware('permission:wheel.screens.manage');

    Route::get('/{screen_key}', [ScreenController::class, 'show'])
        ->name('show')
        ->middleware('permission:wheel.screens.view');

    Route::match(['put', 'patch'], '/{screen_key}', [ScreenController::class, 'update'])
        ->name('update')
        ->middleware('permission:wheel.screens.manage');

    Route::delete('/{screen_key}', [ScreenController::class, 'destroy'])
        ->name('destroy')
        ->middleware('permission:wheel.screens.manage');

    // Actions
    Route::post('/{screen_key}/rotate-secret', [ScreenController::class, 'rotateSecret'])
        ->name('rotate-secret')
        ->middleware('permission:wheel.screens.manage');

    Route::post('/{screen_key}/set-status', [ScreenController::class, 'setStatus'])
        ->name('set-status')
        ->middleware('permission:wheel.screens.manage');

    Route::get('/{screen_key}/health', [ScreenController::class, 'health'])
        ->name('health')
        ->middleware('permission:wheel.screens.view');

    // Screen Campaigns
    Route::get('/{screen_key}/campaigns', [ScreenController::class, 'campaigns'])
        ->name('campaigns.index')
        ->middleware('permission:wheel.screens.view');

    Route::put('/{screen_key}/campaigns', [ScreenController::class, 'syncCampaigns'])
        ->name('campaigns.sync')
        ->middleware('permission:wheel.screens.manage');

    Route::post('/{screen_key}/campaigns/{campaign_key}/activate', [ScreenController::class, 'activateCampaign'])
        ->name('campaigns.activate')
        ->middleware('permission:wheel.screens.manage');
});

// ============================================
// Campaigns
// ============================================
Route::prefix('campaigns')->name('campaigns.')->group(function () {
    Route::get('/', [CampaignController::class, 'index'])
        ->name('index')
        ->middleware('permission:wheel.campaigns.view');

    Route::post('/', [CampaignController::class, 'store'])
        ->name('store')
        ->middleware('permission:wheel.campaigns.manage');

    Route::get('/{campaign_key}', [CampaignController::class, 'show'])
        ->name('show')
        ->middleware('permission:wheel.campaigns.view');

    Route::match(['put', 'patch'], '/{campaign_key}', [CampaignController::class, 'update'])
        ->name('update')
        ->middleware('permission:wheel.campaigns.manage');

    Route::delete('/{campaign_key}', [CampaignController::class, 'destroy'])
        ->name('destroy')
        ->middleware('permission:wheel.campaigns.manage');

    // Lifecycle Actions
    Route::post('/{campaign_key}/activate', [CampaignController::class, 'activate'])
        ->name('activate')
        ->middleware('permission:wheel.campaigns.manage');

    Route::post('/{campaign_key}/pause', [CampaignController::class, 'pause'])
        ->name('pause')
        ->middleware('permission:wheel.campaigns.manage');

    Route::post('/{campaign_key}/end', [CampaignController::class, 'end'])
        ->name('end')
        ->middleware('permission:wheel.campaigns.manage');

    // Duplicate campaign
    Route::post('/{campaign_key}/duplicate', [CampaignController::class, 'duplicate'])
        ->name('duplicate')
        ->middleware('permission:wheel.campaigns.manage');

    // Preview (wheel rendering data)
    Route::get('/{campaign_key}/preview', [CampaignController::class, 'preview'])
        ->name('preview')
        ->middleware('permission:wheel.campaigns.view');

    // Segments (Wheel Config)
    Route::get('/{campaign_key}/segments', [SegmentController::class, 'index'])
        ->name('segments.index')
        ->middleware('permission:wheel.campaigns.view');

    Route::put('/{campaign_key}/segments', [SegmentController::class, 'sync'])
        ->name('segments.sync')
        ->middleware('permission:wheel.campaigns.manage');

    Route::post('/{campaign_key}/segments', [SegmentController::class, 'store'])
        ->name('segments.store')
        ->middleware('permission:wheel.campaigns.manage');

    Route::post('/{campaign_key}/segments/reorder', [SegmentController::class, 'reorder'])
        ->name('segments.reorder')
        ->middleware('permission:wheel.campaigns.manage');

    Route::delete('/{campaign_key}/segments/{segment_key}', [SegmentController::class, 'destroy'])
        ->name('segments.destroy')
        ->middleware('permission:wheel.campaigns.manage');

    // Inventory
    Route::get('/{campaign_key}/inventory', [InventoryController::class, 'index'])
        ->name('inventory.index')
        ->middleware('permission:wheel.inventory.manage');

    Route::put('/{campaign_key}/inventory', [InventoryController::class, 'sync'])
        ->name('inventory.sync')
        ->middleware('permission:wheel.inventory.manage');

    Route::post('/{campaign_key}/inventory/{prize_key}/add', [InventoryController::class, 'addStock'])
        ->name('inventory.add')
        ->middleware('permission:wheel.inventory.manage');

    Route::post('/{campaign_key}/inventory/{prize_key}/reset-daily', [InventoryController::class, 'resetDaily'])
        ->name('inventory.reset-daily')
        ->middleware('permission:wheel.inventory.manage');
});

// ============================================
// Prizes
// ============================================
Route::prefix('prizes')->name('prizes.')->group(function () {
    Route::get('/', [PrizeController::class, 'index'])
        ->name('index')
        ->middleware('permission:wheel.prizes.view');

    Route::post('/', [PrizeController::class, 'store'])
        ->name('store')
        ->middleware('permission:wheel.prizes.manage');

    Route::get('/{prize_key}', [PrizeController::class, 'show'])
        ->name('show')
        ->middleware('permission:wheel.prizes.view');

    Route::match(['put', 'patch'], '/{prize_key}', [PrizeController::class, 'update'])
        ->name('update')
        ->middleware('permission:wheel.prizes.manage');

    Route::delete('/{prize_key}', [PrizeController::class, 'destroy'])
        ->name('destroy')
        ->middleware('permission:wheel.prizes.manage');

    Route::post('/{prize_key}/toggle', [PrizeController::class, 'toggle'])
        ->name('toggle')
        ->middleware('permission:wheel.prizes.manage');
});

// ============================================
// Logs & Events
// ============================================
Route::prefix('logs')->name('logs.')->group(function () {
    Route::get('/events', [EventController::class, 'index'])
        ->name('events')
        ->middleware('permission:wheel.logs.view');
});

// ============================================
// Analytics
// ============================================
Route::prefix('analytics')->name('analytics.')->group(function () {
    Route::get('/summary', [AnalyticsController::class, 'summary'])
        ->name('summary')
        ->middleware('permission:wheel.analytics.view');

    Route::get('/detailed', [AnalyticsController::class, 'detailed'])
        ->name('detailed')
        ->middleware('permission:wheel.analytics.view');

    Route::get('/screens-online', [AnalyticsController::class, 'screensOnline'])
        ->name('screens-online')
        ->middleware('permission:wheel.analytics.view');

    Route::get('/active-campaigns', [AnalyticsController::class, 'activeCampaigns'])
        ->name('active-campaigns')
        ->middleware('permission:wheel.analytics.view');

    Route::get('/spins-today', [AnalyticsController::class, 'spinsToday'])
        ->name('spins-today')
        ->middleware('permission:wheel.analytics.view');

    Route::get('/prizes-won', [AnalyticsController::class, 'prizesWon'])
        ->name('prizes-won')
        ->middleware('permission:wheel.analytics.view');
});

// ============================================
// Players (Jogadores)
// ============================================
Route::prefix('players')->name('players.')->group(function () {
    // Listagem com filtros avançados
    Route::get('/', [\App\Http\Controllers\Api\V1\Admin\Wheel\PlayerController::class, 'index'])
        ->name('index')
        ->middleware('permission:wheel.players.view');

    // Estatísticas por cidade (ANTES de /{player_key})
    Route::get('/stats/by-city', [\App\Http\Controllers\Api\V1\Admin\Wheel\PlayerController::class, 'statsByCity'])
        ->name('stats.by-city')
        ->middleware('permission:wheel.analytics.view');

    // Estatísticas por loja
    Route::get('/stats/by-store', [\App\Http\Controllers\Api\V1\Admin\Wheel\PlayerController::class, 'statsByStore'])
        ->name('stats.by-store')
        ->middleware('permission:wheel.analytics.view');

    // Exportar jogadores
    Route::get('/export', [\App\Http\Controllers\Api\V1\Admin\Wheel\PlayerController::class, 'export'])
        ->name('export')
        ->middleware('permission:wheel.players.view');

    // Detalhes de um jogador
    Route::get('/{player_key}', [\App\Http\Controllers\Api\V1\Admin\Wheel\PlayerController::class, 'show'])
        ->name('show')
        ->middleware('permission:wheel.players.view');

    // Atualizar jogador
    Route::match(['put', 'patch'], '/{player_key}', [\App\Http\Controllers\Api\V1\Admin\Wheel\PlayerController::class, 'update'])
        ->name('update')
        ->middleware('permission:wheel.players.manage');

    // Logs do jogador
    Route::get('/{player_key}/logs', [\App\Http\Controllers\Api\V1\Admin\Wheel\PlayerController::class, 'logs'])
        ->name('logs')
        ->middleware('permission:wheel.players.view');

    // Histórico de giros
    Route::get('/{player_key}/spins', [\App\Http\Controllers\Api\V1\Admin\Wheel\PlayerController::class, 'spins'])
        ->name('spins')
        ->middleware('permission:wheel.players.view');
});

// ============================================
// Prize Rules (Regras de Prêmios)
// ============================================
Route::prefix('prize-rules')->name('prize-rules.')->group(function () {
    // Regra específica
    Route::get('/{rule_id}', [\App\Http\Controllers\Api\V1\Admin\Wheel\PrizeRuleController::class, 'show'])
        ->name('show')
        ->middleware('permission:wheel.campaigns.view');

    Route::match(['put', 'patch'], '/{rule_id}', [\App\Http\Controllers\Api\V1\Admin\Wheel\PrizeRuleController::class, 'update'])
        ->name('update')
        ->middleware('permission:wheel.campaigns.manage');

    Route::delete('/{rule_id}', [\App\Http\Controllers\Api\V1\Admin\Wheel\PrizeRuleController::class, 'destroy'])
        ->name('destroy')
        ->middleware('permission:wheel.campaigns.manage');

    Route::post('/{rule_id}/reset-cooldown', [\App\Http\Controllers\Api\V1\Admin\Wheel\PrizeRuleController::class, 'resetCooldown'])
        ->name('reset-cooldown')
        ->middleware('permission:wheel.campaigns.manage');
});

// Regras dentro de campanha
Route::prefix('campaigns/{campaign_key}/prize-rules')->name('campaigns.prize-rules.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\V1\Admin\Wheel\PrizeRuleController::class, 'index'])
        ->name('index')
        ->middleware('permission:wheel.campaigns.view');

    Route::post('/', [\App\Http\Controllers\Api\V1\Admin\Wheel\PrizeRuleController::class, 'store'])
        ->name('store')
        ->middleware('permission:wheel.campaigns.manage');

    Route::put('/bulk', [\App\Http\Controllers\Api\V1\Admin\Wheel\PrizeRuleController::class, 'bulkUpdate'])
        ->name('bulk')
        ->middleware('permission:wheel.campaigns.manage');
});

// Estado dos prêmios (elegibilidade)
Route::get('/campaigns/{campaign_key}/prize-state', [\App\Http\Controllers\Api\V1\Admin\Wheel\PrizeRuleController::class, 'prizeState'])
    ->name('campaigns.prize-state')
    ->middleware('permission:wheel.campaigns.view');
