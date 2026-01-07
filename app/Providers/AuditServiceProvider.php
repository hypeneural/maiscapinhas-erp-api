<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\ServiceProvider;

/**
 * Provider para registrar serviços de auditoria.
 */
class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registrar AuditContext como singleton (mesmo instância durante toda a request)
        $this->app->singleton(AuditContext::class, function ($app) {
            return new AuditContext();
        });

        // Registrar AuditLogger como singleton
        $this->app->singleton(AuditLogger::class, function ($app) {
            return new AuditLogger(
                $app->make(AuditContext::class)
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
