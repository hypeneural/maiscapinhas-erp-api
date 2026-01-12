<?php

namespace App\Providers;

use App\Models\Announcement;
use App\Models\CashClosing;
use App\Models\Sale;
use App\Observers\CashClosingObserver;
use App\Observers\SaleObserver;
use App\Policies\AnnouncementPolicy;
use Illuminate\Support\Facades\Gate;
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
        // Register model observers
        Sale::observe(SaleObserver::class);
        CashClosing::observe(CashClosingObserver::class);

        // Register policies
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
    }
}
