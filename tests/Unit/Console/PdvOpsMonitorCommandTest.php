<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    DB::purge('sqlite');
    DB::setDefaultConnection('sqlite');
    DB::reconnect('sqlite');

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'sqlite',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);
    config()->set('queue.failed.table', 'failed_jobs');

    config()->set('pdv.monitor_enabled', true);
    config()->set('pdv.queue_name', 'pdv');
    config()->set('pdv.monitor_max_queue_backlog', 10);
    config()->set('pdv.monitor_max_queued_syncs', 10);
    config()->set('pdv.monitor_max_failed_jobs', 10);
    config()->set('pdv.monitor_silent_store_threshold_minutes', 120);
    config()->set('pdv.monitor_max_stale_stores', 0);
    config()->set('pdv.monitor_alert_webhook_url', null);
    config()->set('pdv.monitor_alert_slack_webhook_url', null);
    config()->set('pdv.monitor_alert_emails', []);

    Schema::create('pdv_syncs', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
        $table->string('status', 20)->default('queued');
        $table->dateTime('received_at')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    Schema::create('pdv_store_mappings', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('pdv_store_id')->unique();
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('alias', 100)->nullable();
        $table->boolean('active')->default(true);
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    Schema::create('stores', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->nullable();
        $table->text('connection')->nullable();
        $table->text('queue')->nullable();
        $table->longText('payload');
        $table->longText('exception')->nullable();
        $table->dateTime('failed_at')->nullable();
    });
});

/**
 * @return array<string, mixed>
 */
function runOpsMonitor(bool $forceAlert = false): array
{
    $params = ['--json' => true];
    if ($forceAlert) {
        $params['--force-alert'] = true;
    }

    $exitCode = Artisan::call('pdv:ops-monitor', $params);
    $output = trim(Artisan::output());
    $decoded = json_decode($output, true);
    if (!is_array($decoded)) {
        throw new \RuntimeException('Invalid JSON output from pdv:ops-monitor.');
    }

    $decoded['_exit_code'] = $exitCode;

    return $decoded;
}

function seedMappedStore(int $pdvStoreId, string $alias, string $storeName): int
{
    $storeId = (int) DB::table('stores')->insertGetId([
        'name' => $storeName,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('pdv_store_mappings')->insert([
        'pdv_store_id' => $pdvStoreId,
        'store_id' => $storeId,
        'alias' => $alias,
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $storeId;
}

function seedSync(int $pdvStoreId, string $status, \Carbon\CarbonInterface $receivedAt): void
{
    DB::table('pdv_syncs')->insert([
        'store_pdv_id' => $pdvStoreId,
        'status' => $status,
        'received_at' => $receivedAt->toDateTimeString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('monitor reports stale store metric and raises stale_stores_high issue', function () {
    seedMappedStore(10, 'loja-10', 'Loja 10');
    seedMappedStore(11, 'loja-11', 'Loja 11');

    seedSync(10, 'processed', now()->subMinutes(5));
    seedSync(11, 'processed', now()->subHours(3));

    $result = runOpsMonitor();

    expect($result['_exit_code'])->toBe(1);
    expect($result['status'])->toBe('alert');
    expect(data_get($result, 'metrics.stale_stores_available'))->toBeTrue();
    expect(data_get($result, 'metrics.stale_stores_count'))->toBe(1);
    expect(data_get($result, 'metrics.stale_stores.0.store_pdv_id'))->toBe(11);

    $issueNames = collect($result['issues'] ?? [])->pluck('name')->all();
    expect($issueNames)->toContain('stale_stores_high');
});

test('alert payload includes stale store details in webhook notification', function () {
    seedMappedStore(11, 'loja-11', 'Loja 11');
    seedSync(11, 'processed', now()->subHours(4));

    config()->set('pdv.monitor_alert_webhook_url', 'https://hooks.test/pdv-monitor');
    Http::fake([
        'https://hooks.test/pdv-monitor' => Http::response(['ok' => true], 200),
    ]);

    $result = runOpsMonitor(true);

    expect($result['_exit_code'])->toBe(1);
    expect($result['status'])->toBe('alert');
    expect(data_get($result, 'notification.sent'))->toBeTrue();
    expect(data_get($result, 'notification.channels'))->toContain('webhook');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        $data = $request->data();
        $message = (string) data_get($data, 'message', '');

        return $request->url() === 'https://hooks.test/pdv-monitor'
            && data_get($data, 'event') === 'pdv_monitor_alert'
            && str_contains($message, 'stale_store_list=')
            && str_contains($message, '11:loja-11');
    });
});

test('monitor stays healthy when thresholds are respected', function () {
    seedMappedStore(10, 'loja-10', 'Loja 10');
    seedSync(10, 'processed', now()->subMinutes(10));

    $result = runOpsMonitor();

    expect($result['_exit_code'])->toBe(0);
    expect($result['status'])->toBe('ok');
    expect(data_get($result, 'metrics.stale_stores_count'))->toBe(0);
    expect($result['issues'])->toBeArray()->toHaveCount(0);
});
