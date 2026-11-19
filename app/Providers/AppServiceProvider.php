<?php

namespace App\Providers;

use App\Models\Announcement;
use App\Models\CashClosing;
use App\Models\Sale;
use App\Observers\CashClosingObserver;
use App\Observers\SaleObserver;
use App\Policies\AnnouncementPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('pdv', function (Request $request) {
            $storeHint = $request->header('X-PDV-Store', 'unknown');
            $key = $request->ip() . '|' . $storeHint;

            return Limit::perMinute((int) config('pdv.rate_limit_per_minute', 180))
                ->by($key);
        });

        // Proxy-safe rate limiters for TV Digital Signage (avoids shared 127.0.0.1 proxy bucket!)
        RateLimiter::for('tv-runtime', function (Request $request) {
            $slug = (string) ($request->route('slugOrToken') ?? 'unknown');

            return Limit::perMinute((int) config('tv.runtime_rate_limit_per_minute', 180))
                ->by('tv-runtime|' . $slug);
        });

        RateLimiter::for('tv-status', function (Request $request) {
            $ip = $request->header('CF-Connecting-IP')
                ?: trim(explode(',', (string) $request->header('X-Forwarded-For', ''))[0] ?? '')
                ?: $request->ip();

            return Limit::perMinute((int) config('tv.status_rate_limit_per_minute', 120))
                ->by('tv-status|' . ($ip ?: 'global'));
        });

        // Register model observers
        Sale::observe(SaleObserver::class);
        CashClosing::observe(CashClosingObserver::class);

        // Register policies
        Gate::policy(Announcement::class, AnnouncementPolicy::class);

        // Super Admin bypass for all gates and policies
        Gate::before(function ($user, $ability) {
            if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return true;
            }
        });
    }
}
