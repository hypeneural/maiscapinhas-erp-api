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
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\CapaPersonalizadaController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\ProducaoCarrinhoController;
use App\Http\Controllers\Api\V1\ProducaoPedidoController;
use App\Http\Controllers\Api\V1\ProducaoAdminController;
use App\Http\Controllers\Api\V1\FabricaPedidoController;
use App\Http\Controllers\Api\V1\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Api\V1\Admin\PermissionController as AdminPermissionController;
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
        Route::get('/', [AnnouncementController::class, 'adminIndex'])->name('index')->middleware('permission:announcements.view');
        Route::post('/', [AnnouncementController::class, 'store'])->name('store')->middleware('permission:announcements.create');
        Route::get('/{announcement}', [AnnouncementController::class, 'show'])->name('show')->middleware('permission:announcements.view');
        Route::put('/{announcement}', [AnnouncementController::class, 'update'])->name('update')->middleware('permission:announcements.update');
        Route::delete('/{announcement}', [AnnouncementController::class, 'destroy'])->name('destroy')->middleware('permission:announcements.delete');
        Route::post('/{announcement}/seen', [AnnouncementController::class, 'seen'])->name('seen');
        Route::post('/{announcement}/ack', [AnnouncementController::class, 'ack'])->name('ack');
        Route::post('/{announcement}/dismiss', [AnnouncementController::class, 'dismiss'])->name('dismiss');
        Route::post('/{announcement}/publish', [AnnouncementController::class, 'publish'])->name('publish')->middleware('permission:announcements.update');
        Route::post('/{announcement}/archive', [AnnouncementController::class, 'archive'])->name('archive')->middleware('permission:announcements.update');
        Route::get('/{announcement}/stats', [AnnouncementController::class, 'stats'])->name('stats')->middleware('permission:announcements.view');
        Route::get('/{announcement}/receipts', [AnnouncementController::class, 'receipts'])->name('receipts')->middleware('permission:announcements.view');
        Route::post('/{announcement}/duplicate', [AnnouncementController::class, 'duplicate'])->name('duplicate')->middleware('permission:announcements.create');
        Route::post('/{announcement}/republish', [AnnouncementController::class, 'republish'])->name('republish')->middleware('permission:announcements.update');
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
        Route::get('/', [SaleController::class, 'index'])->name('index')->middleware('permission:sales.view');
        Route::post('/', [SaleController::class, 'store'])->name('store')->middleware('permission:sales.create');
        Route::get('/{sale}', [SaleController::class, 'show'])->name('show')->middleware('permission:sales.view');
        Route::put('/{sale}', [SaleController::class, 'update'])->name('update')->middleware('permission:sales.update');
        Route::delete('/{sale}', [SaleController::class, 'destroy'])->name('destroy')->middleware('permission:sales.delete');
    });

    // Cash Management
    Route::prefix('cash')->name('cash.')->group(function () {
        // Shifts
        Route::prefix('shifts')->name('shifts.')->group(function () {
            Route::get('/', [CashShiftController::class, 'index'])->name('index')->middleware('permission:caixa.view');
            Route::get('/pending', [CashShiftController::class, 'pending'])->name('pending')->middleware('permission:caixa.closing.approve');
            Route::get('/divergent', [CashShiftController::class, 'divergent'])->name('divergent')->middleware('permission:caixa.closing.approve');
            Route::post('/', [CashShiftController::class, 'store'])->name('store')->middleware('permission:caixa.shift.open');
            Route::get('/{shift}', [CashShiftController::class, 'show'])->name('show')->middleware('permission:caixa.view');
        });

        // Closings (actions on shifts)
        Route::prefix('closings')->name('closings.')->group(function () {
            Route::get('/{shift}', [CashClosingController::class, 'show'])->name('show')->middleware('permission:caixa.view');
            Route::post('/{shift}', [CashClosingController::class, 'store'])->name('store')->middleware('permission:caixa.closing.create');
            Route::put('/{shift}', [CashClosingController::class, 'update'])->name('update')->middleware('permission:caixa.closing.create');
            Route::post('/{shift}/submit', [CashClosingController::class, 'submit'])->name('submit')->middleware('permission:caixa.closing.create');
            Route::post('/{shift}/approve', [CashClosingController::class, 'approve'])->name('approve')->middleware('permission:caixa.closing.approve');
            Route::post('/{shift}/reject', [CashClosingController::class, 'reject'])->name('reject')->middleware('permission:caixa.closing.reject');
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
        Route::get('/', [CustomerController::class, 'index'])->name('index')->middleware('permission:customers.view');
        Route::post('/', [CustomerController::class, 'store'])->name('store')->middleware('permission:customers.create');
        Route::get('/{customer}', [CustomerController::class, 'show'])->name('show')->middleware('permission:customers.view');
        Route::match(['put', 'patch'], '/{customer}', [CustomerController::class, 'update'])->name('update')->middleware('permission:customers.update');
        Route::delete('/{customer}', [CustomerController::class, 'destroy'])->name('destroy')->middleware('permission:customers.delete');

        // Customer Devices
        Route::get('/{customer}/devices', [CustomerController::class, 'devices'])->name('devices.index')->middleware('permission:customers.view');
        Route::post('/{customer}/devices', [CustomerController::class, 'storeDevice'])->name('devices.store')->middleware('permission:customers.update');
        Route::patch('/{customer}/devices/{device}', [CustomerController::class, 'updateDevice'])->name('devices.update')->middleware('permission:customers.update');
        Route::delete('/{customer}/devices/{device}', [CustomerController::class, 'destroyDevice'])->name('devices.destroy')->middleware('permission:customers.update');
    });

    // ============================================
    // Phone Catalog Module (Brands & Models)
    // ============================================
    Route::prefix('phone-catalog')->name('phone-catalog.')->group(function () {
        require app_path('Modules/CatalogoAparelhos/routes.php');
    });

    // ============================================
    // Payment Methods (Formas de Pagamento)
    // ============================================
    Route::apiResource('payment-methods', PaymentMethodController::class);

    // ============================================
    // Pedidos (CRUD + status management)
    // ============================================
    Route::prefix('pedidos')->name('pedidos.')->group(function () {
        Route::get('/', [PedidoController::class, 'index'])->name('index')->middleware('permission:pedidos.view');
        Route::post('/', [PedidoController::class, 'store'])->name('store')->middleware('permission:pedidos.create');
        Route::get('/{pedido}', [PedidoController::class, 'show'])->name('show')->middleware('permission:pedidos.view');
        Route::patch('/{pedido}', [PedidoController::class, 'update'])->name('update')->middleware('permission:pedidos.update');
        Route::delete('/{pedido}', [PedidoController::class, 'destroy'])->name('destroy')->middleware('permission:pedidos.delete');
        Route::patch('/{pedido}/status', [PedidoController::class, 'updateStatus'])->name('status')->middleware('permission:pedidos.status.update');
        Route::post('/bulk-status', [PedidoController::class, 'bulkStatus'])->name('bulk-status')->middleware('permission:pedidos.bulk-status');
    });

    // ============================================
    // Capas Personalizadas (CRUD + status + production + payment + photo)
    // ============================================
    Route::prefix('capas-personalizadas')->name('capas-personalizadas.')->group(function () {
        Route::get('/', [CapaPersonalizadaController::class, 'index'])->name('index')->middleware('permission:capas.view');
        Route::post('/', [CapaPersonalizadaController::class, 'store'])->name('store')->middleware('permission:capas.create');
        Route::get('/{capas_personalizada}', [CapaPersonalizadaController::class, 'show'])->name('show')->middleware('permission:capas.view');
        Route::patch('/{capas_personalizada}', [CapaPersonalizadaController::class, 'update'])->name('update')->middleware('permission:capas.update');
        Route::delete('/{capas_personalizada}', [CapaPersonalizadaController::class, 'destroy'])->name('destroy')->middleware('permission:capas.delete');
        Route::patch('/{capas_personalizada}/status', [CapaPersonalizadaController::class, 'updateStatus'])->name('status')->middleware('permission:capas.status.update');
        Route::post('/bulk-status', [CapaPersonalizadaController::class, 'bulkStatus'])->name('bulk-status')->middleware('permission:capas.bulk-status');
        Route::post('/send-to-production', [CapaPersonalizadaController::class, 'sendToProduction'])->name('send-to-production')->middleware('permission:capas.send-production');
        Route::patch('/{capas_personalizada}/payment', [CapaPersonalizadaController::class, 'payment'])->name('payment')->middleware('permission:capas.payment.update');
        Route::post('/{capas_personalizada}/photo', [CapaPersonalizadaController::class, 'uploadPhoto'])->name('photo')->middleware('permission:capas.update');
        Route::delete('/{capas_personalizada}/photo', [CapaPersonalizadaController::class, 'deletePhoto'])->name('photo.delete')->middleware('permission:capas.update');
        Route::post('/{capas_personalizada}/gerar-token-upload', [CapaPersonalizadaController::class, 'gerarTokenUpload'])->name('gerar-token-upload')->middleware('permission:capas.update');
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
            Route::post('/{pedido}/recusar-itens', [FabricaPedidoController::class, 'rejectItems'])->name('recusar-itens');
            Route::get('/{pedido}/itens/{item}/foto', [FabricaPedidoController::class, 'downloadPhoto'])->name('item.foto');
        });
    });

    // ============================================
    // Comunicados - Comunicados Internos
    // ============================================
    Route::prefix('comunicados')->name('comunicados.')->middleware('admin')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\ComunicadosController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\Api\V1\ComunicadosController::class, 'store'])->name('store');
        Route::get('/{comunicado}', [\App\Http\Controllers\Api\V1\ComunicadosController::class, 'show'])->name('show');
        Route::patch('/{comunicado}', [\App\Http\Controllers\Api\V1\ComunicadosController::class, 'update'])->name('update');
        Route::delete('/{comunicado}', [\App\Http\Controllers\Api\V1\ComunicadosController::class, 'destroy'])->name('destroy');
    });

    // ============================================
    // WhatsApp Module (Instances & Messages)
    // ============================================
    Route::prefix('whatsapp')->name('whatsapp.')->group(function () {
        require app_path('Modules/WhatsAppInstances/routes.php');
    });

    // ============================================
    // Roles & Permissions (Super Admin only)
    // ============================================
    Route::prefix('admin')->name('admin.')->middleware('super-admin')->group(function () {
        // Roles CRUD
        Route::apiResource('roles', AdminRoleController::class);
        Route::post('roles/{role}/permissions', [AdminRoleController::class, 'syncPermissions'])
            ->name('roles.sync-permissions');

        // Permissions (Full CRUD + utilities)
        Route::get('permissions', [AdminPermissionController::class, 'index'])->name('permissions.index');
        Route::post('permissions', [AdminPermissionController::class, 'store'])->name('permissions.store');
        Route::get('permissions/grouped', [AdminPermissionController::class, 'grouped'])->name('permissions.grouped');
        Route::get('permissions/by-type', [AdminPermissionController::class, 'byType'])->name('permissions.by-type');
        Route::get('permissions/modules', [AdminPermissionController::class, 'modules'])->name('permissions.modules');
        Route::get('permissions/conventions', [AdminPermissionController::class, 'conventions'])->name('permissions.conventions');
        Route::post('permissions/bulk', [AdminPermissionController::class, 'bulkStore'])->name('permissions.bulk');

        // Permission Features (must be before {permission} wildcard)
        Route::post('permissions/preview', [AdminPermissionController::class, 'preview'])->name('permissions.preview');
        Route::post('permissions/bulk-grant', [AdminPermissionController::class, 'bulkGrant'])->name('permissions.bulk-grant');
        Route::get('permissions/most-granted', [AdminPermissionController::class, 'mostGranted'])->name('permissions.most-granted');

        // Permission CRUD with wildcard (must be after specific routes)
        Route::get('permissions/{permission}', [AdminPermissionController::class, 'show'])->name('permissions.show');
        Route::put('permissions/{permission}', [AdminPermissionController::class, 'update'])->name('permissions.update');
        Route::delete('permissions/{permission}', [AdminPermissionController::class, 'destroy'])->name('permissions.destroy');
        Route::get('permissions/{permission}/users', [AdminPermissionController::class, 'usersByPermission'])->name('permissions.users');

        // User permission management
        Route::post('users/{user}/permissions/copy-from/{source}', [AdminPermissionController::class, 'copyFrom'])->name('users.permissions.copy-from');
        Route::get('users/{user}/permissions/audit-log', [AdminPermissionController::class, 'userAuditLog'])->name('users.permissions.audit-log');

        // Role Clone and Permissions
        Route::post('roles/{role}/clone', [\App\Http\Controllers\Api\V1\Admin\RoleController::class, 'clone'])->name('roles.clone');
        Route::put('roles/{role}/permissions', [\App\Http\Controllers\Api\V1\Admin\RoleController::class, 'updatePermissions'])->name('roles.update-permissions');

        // User Permission Overrides
        Route::prefix('users/{user}/permission-overrides')->name('users.permission-overrides.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\Admin\UserPermissionOverrideController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Api\V1\Admin\UserPermissionOverrideController::class, 'store'])->name('store');
            Route::post('/bulk', [\App\Http\Controllers\Api\V1\Admin\UserPermissionOverrideController::class, 'bulkStore'])->name('bulk');
            Route::delete('/clear', [\App\Http\Controllers\Api\V1\Admin\UserPermissionOverrideController::class, 'clear'])->name('clear');
            Route::get('/effective', [\App\Http\Controllers\Api\V1\Admin\UserPermissionOverrideController::class, 'effective'])->name('effective');
            Route::put('/{override}', [\App\Http\Controllers\Api\V1\Admin\UserPermissionOverrideController::class, 'update'])->name('update');
            Route::delete('/{override}', [\App\Http\Controllers\Api\V1\Admin\UserPermissionOverrideController::class, 'destroy'])->name('destroy');
        });

        // Store Permission Overrides
        Route::prefix('stores/{store}/permission-overrides')->name('stores.permission-overrides.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\Admin\StorePermissionOverrideController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Api\V1\Admin\StorePermissionOverrideController::class, 'store'])->name('store');
            Route::post('/bulk', [\App\Http\Controllers\Api\V1\Admin\StorePermissionOverrideController::class, 'bulkStore'])->name('bulk');
            Route::delete('/clear', [\App\Http\Controllers\Api\V1\Admin\StorePermissionOverrideController::class, 'clear'])->name('clear');
            Route::put('/{override}', [\App\Http\Controllers\Api\V1\Admin\StorePermissionOverrideController::class, 'update'])->name('update');
            Route::delete('/{override}', [\App\Http\Controllers\Api\V1\Admin\StorePermissionOverrideController::class, 'destroy'])->name('destroy');
        });

        // User Role Assignments
        Route::get('roles/available', [\App\Http\Controllers\Api\V1\Admin\UserRoleController::class, 'availableRoles'])
            ->name('roles.available');
        Route::prefix('users/{user}/roles')->name('users.roles.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\Admin\UserRoleController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Api\V1\Admin\UserRoleController::class, 'store'])->name('store');
            Route::put('/sync', [\App\Http\Controllers\Api\V1\Admin\UserRoleController::class, 'sync'])->name('sync');
            Route::delete('/{assignment}', [\App\Http\Controllers\Api\V1\Admin\UserRoleController::class, 'destroy'])->name('destroy');
        });

        // Modules Management
        Route::prefix('modules')->name('modules.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'index'])->name('index');
            Route::get('/{module}', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'show'])->name('show');
            Route::get('/{module}/full', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'full'])->name('full');
            Route::post('/{module}/install', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'install'])->name('install');
            Route::post('/{module}/activate', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'activate'])->name('activate');
            Route::post('/{module}/deactivate', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'deactivate'])->name('deactivate');
            Route::get('/{module}/transitions', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'transitions'])->name('transitions');
            Route::put('/{module}/transitions', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'updateTransitions'])->name('transitions.update');
            Route::get('/{module}/stores', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'stores'])->name('stores.index');
            Route::post('/{module}/stores/{store}/activate', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'activateForStore'])->name('stores.activate');
            Route::post('/{module}/stores/{store}/deactivate', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'deactivateForStore'])->name('stores.deactivate');

            // Phase 2: Granular editing endpoints
            Route::get('/{module}/texts', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'getTexts'])->name('texts.show');
            Route::put('/{module}/texts', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'updateTexts'])->name('texts.update');
            Route::put('/{module}/actions/{action}', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'updateAction'])->name('actions.update');
            Route::post('/{module}/actions', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'createAction'])->name('actions.create');
            Route::delete('/{module}/actions/{action}', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'deleteAction'])->name('actions.delete');
            Route::get('/{module}/audit-log', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'getAuditLog'])->name('audit-log');

            // Module Configuration
            Route::get('/{module}/config', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'getConfig'])->name('config.show');
            Route::patch('/{module}/config', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'updateConfig'])->name('config.update');
            Route::post('/{module}/config/reset', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'resetConfig'])->name('config.reset');
            Route::get('/{module}/stores/{store}/config', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'getStoreConfig'])->name('config.store.show');
            Route::patch('/{module}/stores/{store}/config', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'updateStoreConfig'])->name('config.store.update');

            // Status Management (CRUD)
            Route::get('/{module}/schema', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'getSchema'])->name('schema');
            Route::patch('/{module}/statuses/{status}', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'updateStatus'])->name('statuses.update');
            Route::post('/{module}/statuses', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'createStatus'])->name('statuses.create');
            Route::delete('/{module}/statuses/{status}', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'deleteStatus'])->name('statuses.delete');
            Route::post('/{module}/preview-impact', [\App\Http\Controllers\Api\V1\Admin\ModuleController::class, 'previewImpact'])->name('preview-impact');
        });

        // Graph Visualization (React Flow format)
        Route::prefix('graph')->name('graph.')->group(function () {
            Route::get('/overview', [\App\Http\Controllers\Api\V1\Admin\GraphController::class, 'overview'])->name('overview');
            Route::get('/role/{role}', [\App\Http\Controllers\Api\V1\Admin\GraphController::class, 'role'])->name('role');
            Route::get('/user/{user}', [\App\Http\Controllers\Api\V1\Admin\GraphController::class, 'user'])->name('user');
            Route::get('/store/{store}', [\App\Http\Controllers\Api\V1\Admin\GraphController::class, 'store'])->name('store');
            Route::get('/module/{module}', [\App\Http\Controllers\Api\V1\Admin\GraphController::class, 'module'])->name('module');
        });
    });



});
