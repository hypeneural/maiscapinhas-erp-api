<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\StoreController as AdminStoreController;
use App\Http\Controllers\Api\V1\Admin\StoreUserController as AdminStoreUserController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Http\Controllers\Api\V1\BioController;
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
use App\Http\Controllers\Api\V1\UserKpiController;
use App\Http\Controllers\Api\V1\VersionController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\PhoneBrandController;
use App\Http\Controllers\Api\V1\PhoneModelController;
use App\Http\Controllers\Api\V1\PedidoController;
use App\Http\Controllers\Api\V1\CapaPersonalizadaController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\ProducaoCarrinhoController;
use App\Http\Controllers\Api\V1\ProducaoPedidoController;
use App\Http\Controllers\Api\V1\ProducaoAdminController;
use App\Http\Controllers\Api\V1\FabricaPedidoController;
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
    Route::post('/forgot-password/whatsapp', [PasswordResetController::class, 'forgotPasswordWhatsApp'])->name('forgot-password.whatsapp');
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->name('reset-password');
    Route::post('/reset-password/code', [PasswordResetController::class, 'resetPasswordWithCode'])->name('reset-password.code');
});

// Bio routes (public - for Instagram Bio page)
Route::prefix('bio')->name('bio.')->group(function () {
    Route::get('/stores', [BioController::class, 'index'])->name('stores');
    Route::get('/stores/{store}', [BioController::class, 'show'])->name('stores.show');
});

// Public upload for Capas Personalizadas (token-based authentication)
Route::post('/capas-personalizadas/{capa}/upload-publico', [CapaPersonalizadaController::class, 'uploadPublico'])
    ->name('capas-personalizadas.upload-publico');

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
    Route::put('/me', [MeController::class, 'update'])->name('me.update');

    // ============================================
    // Announcements (internal communication)
    // ============================================

    // User-facing announcement routes
    Route::prefix('me/announcements')->name('me.announcements.')->group(function () {
        Route::get('/active', [AnnouncementController::class, 'activeForCurrentUser'])->name('active');
        Route::get('/', [AnnouncementController::class, 'userHistory'])->name('index');
    });

    // Announcement CRUD and actions
    Route::prefix('announcements')->name('announcements.')->group(function () {
        Route::get('/', [AnnouncementController::class, 'adminIndex'])->name('index');
        Route::post('/', [AnnouncementController::class, 'store'])->name('store');
        Route::get('/{announcement}', [AnnouncementController::class, 'show'])->name('show');
        Route::put('/{announcement}', [AnnouncementController::class, 'update'])->name('update');
        Route::delete('/{announcement}', [AnnouncementController::class, 'destroy'])->name('destroy');
        Route::post('/{announcement}/seen', [AnnouncementController::class, 'seen'])->name('seen');
        Route::post('/{announcement}/ack', [AnnouncementController::class, 'ack'])->name('ack');
        Route::post('/{announcement}/dismiss', [AnnouncementController::class, 'dismiss'])->name('dismiss');
        Route::post('/{announcement}/publish', [AnnouncementController::class, 'publish'])->name('publish');
        Route::post('/{announcement}/archive', [AnnouncementController::class, 'archive'])->name('archive');
        Route::get('/{announcement}/stats', [AnnouncementController::class, 'stats'])->name('stats');
        Route::get('/{announcement}/receipts', [AnnouncementController::class, 'receipts'])->name('receipts');
        Route::post('/{announcement}/duplicate', [AnnouncementController::class, 'duplicate'])->name('duplicate');
        Route::post('/{announcement}/republish', [AnnouncementController::class, 'republish'])->name('republish');
    });

    // Stores (user's stores)
    Route::prefix('stores')->name('stores.')->group(function () {
        Route::get('/', [StoreController::class, 'index'])->name('index');
        Route::get('/all', [StoreController::class, 'all'])->name('all');
        Route::get('/{store}', [StoreController::class, 'show'])->name('show');
        Route::get('/{store}/sellers', [StoreController::class, 'sellers'])->name('sellers');
        Route::get('/{store}/users', [StoreController::class, 'users'])->name('users');
        Route::put('/{store}/photo', [StoreController::class, 'updatePhoto'])->name('photo');
        Route::post('/{store}/photo', [StoreController::class, 'updatePhoto'])->name('photo.post');
    });

    // Users (avatar upload)
    Route::prefix('users')->name('users.')->group(function () {
        Route::put('/{user}/avatar', [AvatarController::class, 'updateAvatar'])->name('avatar');
        Route::post('/{user}/avatar', [AvatarController::class, 'updateAvatar'])->name('avatar.post');
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
            Route::post('/{shift}', [CashClosingController::class, 'store'])->name('store');
            Route::put('/{shift}', [CashClosingController::class, 'update'])->name('update');
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
    // Users (protected endpoints)
    // ============================================
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/birthdays', [RankingController::class, 'birthdays'])->name('birthdays');
        Route::get('/kpis', UserKpiController::class)->name('kpis');
    });


    // ============================================
    // Customers (CRUD + devices)
    // ============================================
    Route::prefix('customers')->name('customers.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::post('/', [CustomerController::class, 'store'])->name('store');
        Route::get('/{customer}', [CustomerController::class, 'show'])->name('show');
        Route::patch('/{customer}', [CustomerController::class, 'update'])->name('update');
        Route::delete('/{customer}', [CustomerController::class, 'destroy'])->name('destroy');

        // Customer Devices
        Route::get('/{customer}/devices', [CustomerController::class, 'devices'])->name('devices.index');
        Route::post('/{customer}/devices', [CustomerController::class, 'storeDevice'])->name('devices.store');
        Route::patch('/{customer}/devices/{device}', [CustomerController::class, 'updateDevice'])->name('devices.update');
        Route::delete('/{customer}/devices/{device}', [CustomerController::class, 'destroyDevice'])->name('devices.destroy');
    });

    // ============================================
    // Phone Catalog (Brands & Models)
    // ============================================
    Route::apiResource('phone-brands', PhoneBrandController::class);
    Route::apiResource('phone-models', PhoneModelController::class);

    // ============================================
    // Pedidos (CRUD + status management)
    // ============================================
    Route::prefix('pedidos')->name('pedidos.')->group(function () {
        Route::get('/', [PedidoController::class, 'index'])->name('index');
        Route::post('/', [PedidoController::class, 'store'])->name('store');
        Route::get('/{pedido}', [PedidoController::class, 'show'])->name('show');
        Route::patch('/{pedido}', [PedidoController::class, 'update'])->name('update');
        Route::delete('/{pedido}', [PedidoController::class, 'destroy'])->name('destroy');
        Route::patch('/{pedido}/status', [PedidoController::class, 'updateStatus'])->name('status');
        Route::post('/bulk-status', [PedidoController::class, 'bulkStatus'])->name('bulk-status');
    });

    // ============================================
    // Capas Personalizadas (CRUD + status + production + payment + photo)
    // ============================================
    Route::prefix('capas-personalizadas')->name('capas-personalizadas.')->group(function () {
        Route::get('/', [CapaPersonalizadaController::class, 'index'])->name('index');
        Route::post('/', [CapaPersonalizadaController::class, 'store'])->name('store');
        Route::get('/{capas_personalizada}', [CapaPersonalizadaController::class, 'show'])->name('show');
        Route::patch('/{capas_personalizada}', [CapaPersonalizadaController::class, 'update'])->name('update');
        Route::delete('/{capas_personalizada}', [CapaPersonalizadaController::class, 'destroy'])->name('destroy');
        Route::patch('/{capas_personalizada}/status', [CapaPersonalizadaController::class, 'updateStatus'])->name('status');
        Route::post('/bulk-status', [CapaPersonalizadaController::class, 'bulkStatus'])->name('bulk-status');
        Route::post('/send-to-production', [CapaPersonalizadaController::class, 'sendToProduction'])->name('send-to-production');
        Route::patch('/{capas_personalizada}/payment', [CapaPersonalizadaController::class, 'payment'])->name('payment');
        Route::post('/{capas_personalizada}/photo', [CapaPersonalizadaController::class, 'uploadPhoto'])->name('photo');
        Route::delete('/{capas_personalizada}/photo', [CapaPersonalizadaController::class, 'deletePhoto'])->name('photo.delete');
        Route::post('/{capas_personalizada}/gerar-token-upload', [CapaPersonalizadaController::class, 'gerarTokenUpload'])->name('gerar-token-upload');
    });

    // ============================================
    // Admin Routes (admin only)
    // ============================================
    Route::prefix('admin')->name('admin.')->group(function () {

        // Users Management
        Route::apiResource('users', AdminUserController::class);

        // User Bulk Store Operations
        Route::put('/users/{user}/stores', [AdminUserController::class, 'syncStores'])
            ->name('users.stores.sync');
        Route::post('/users/{user}/stores/bulk', [AdminUserController::class, 'bulkAddStores'])
            ->name('users.stores.bulk-add');
        Route::patch('/users/{user}/stores/bulk', [AdminUserController::class, 'bulkUpdateStores'])
            ->name('users.stores.bulk-update');
        Route::delete('/users/{user}/stores/bulk', [AdminUserController::class, 'bulkRemoveStores'])
            ->name('users.stores.bulk-remove');

        // Stores Management
        Route::apiResource('stores', AdminStoreController::class);
        Route::post('/stores/validate-hours', [AdminStoreController::class, 'validateOpeningHours'])
            ->name('stores.validate-hours');

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

    // ============================================
    // Produção - Carrinho e Pedidos (Admin)
    // ============================================
    Route::prefix('producao')->name('producao.')->group(function () {
        // Carrinho
        Route::prefix('carrinho')->name('carrinho.')->group(function () {
            Route::get('/', [ProducaoCarrinhoController::class, 'index'])->name('index');
            Route::post('/validar', [ProducaoCarrinhoController::class, 'validate'])->name('validar');
            Route::post('/itens', [ProducaoCarrinhoController::class, 'addItems'])->name('itens.add');
            Route::delete('/itens/bulk', [ProducaoCarrinhoController::class, 'bulkRemoveItems'])->name('itens.bulk-remove');
            Route::delete('/itens/{item}', [ProducaoCarrinhoController::class, 'removeItem'])->name('itens.remove');
            Route::post('/fechar', [ProducaoCarrinhoController::class, 'close'])->name('fechar');
            Route::delete('/', [ProducaoCarrinhoController::class, 'cancel'])->name('cancel');
        });

        // Pedidos (Admin)
        Route::prefix('pedidos')->name('pedidos.')->group(function () {
            Route::get('/', [ProducaoPedidoController::class, 'index'])->name('index');
            Route::get('/{pedido}', [ProducaoPedidoController::class, 'show'])->name('show');
            Route::get('/{pedido}/timeline', [ProducaoPedidoController::class, 'timeline'])->name('timeline');
            Route::patch('/{pedido}/receber', [ProducaoPedidoController::class, 'receive'])->name('receber');
            Route::delete('/{pedido}', [ProducaoPedidoController::class, 'cancel'])->name('cancel');
        });

        // Admin Actions
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::post('/limpar-itens-cancelados', [ProducaoAdminController::class, 'cleanupOrphanedItems'])
                ->name('limpar-itens-cancelados');
        });
    });

    // ============================================
    // Fábrica - Portal da Fábrica
    // ============================================
    Route::prefix('fabrica')->name('fabrica.')->middleware('fabrica')->group(function () {
        Route::prefix('pedidos')->name('pedidos.')->group(function () {
            Route::get('/', [FabricaPedidoController::class, 'index'])->name('index');
            Route::get('/{pedido}', [FabricaPedidoController::class, 'show'])->name('show');
            Route::patch('/{pedido}/aceitar', [FabricaPedidoController::class, 'accept'])->name('aceitar');
            Route::patch('/{pedido}/despachar', [FabricaPedidoController::class, 'dispatch'])->name('despachar');
            Route::get('/{pedido}/itens/{item}/foto', [FabricaPedidoController::class, 'downloadPhoto'])->name('item.foto');
        });
    });

    // ============================================
    // WhatsApp - Administração (Super Admin only)
    // ============================================
    Route::prefix('admin/whatsapp')->name('admin.whatsapp.')->middleware('super-admin')->group(function () {
        Route::apiResource('instances', \App\Http\Controllers\Api\V1\Admin\WhatsAppInstanceController::class);
        Route::post('instances/{instance}/set-default', [\App\Http\Controllers\Api\V1\Admin\WhatsAppInstanceController::class, 'setDefault'])
            ->name('instances.set-default');
        Route::delete('instances/{instance}/secrets/api-key', [\App\Http\Controllers\Api\V1\Admin\WhatsAppInstanceController::class, 'clearApiKey'])
            ->name('instances.clear-api-key');
        Route::delete('instances/{instance}/secrets/token', [\App\Http\Controllers\Api\V1\Admin\WhatsAppInstanceController::class, 'clearToken'])
            ->name('instances.clear-token');
        Route::get('instances/{instance}/state', [\App\Http\Controllers\Api\V1\Admin\WhatsAppInstanceController::class, 'state'])
            ->name('instances.state');
        Route::get('instances/{instance}/connect', [\App\Http\Controllers\Api\V1\Admin\WhatsAppInstanceController::class, 'connect'])
            ->name('instances.connect');
        Route::post('instances/{instance}/test', [\App\Http\Controllers\Api\V1\Admin\WhatsAppInstanceController::class, 'test'])
            ->name('instances.test');
    });

    // ============================================
    // WhatsApp - Mensagens (authenticated users)
    // ============================================
    Route::prefix('whatsapp')->name('whatsapp.')->group(function () {
        Route::post('instances/{instance}/messages/text', [\App\Http\Controllers\Api\V1\WhatsAppMessageController::class, 'sendText'])
            ->name('messages.text');
        Route::post('instances/{instance}/messages/media', [\App\Http\Controllers\Api\V1\WhatsAppMessageController::class, 'sendMedia'])
            ->name('messages.media');
        Route::post('instances/{instance}/numbers/check', [\App\Http\Controllers\Api\V1\WhatsAppMessageController::class, 'checkNumbers'])
            ->name('numbers.check');
    });

});
