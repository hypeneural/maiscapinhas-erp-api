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

        // Register model observers
        Sale::observe(SaleObserver::class);
        CashClosing::observe(CashClosingObserver::class);

        // Register policies
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
    }
}
