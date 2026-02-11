<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('pdv:purge-raw-payloads')
    ->dailyAt('03:10')
    ->withoutOverlapping();

Schedule::command(sprintf(
    'pdv:retry-failed --limit=%d --max-attempts=%d --older-than-minutes=%d',
    (int) config('pdv.retry_failed_limit', 200),
    (int) config('pdv.retry_failed_max_attempts', 8),
    (int) config('pdv.retry_failed_older_than_minutes', 15)
))
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->when(static fn () => (bool) config('pdv.retry_failed_enabled', false));
