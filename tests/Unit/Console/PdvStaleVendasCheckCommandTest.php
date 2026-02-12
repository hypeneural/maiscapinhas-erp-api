<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

    config()->set('pdv.stale_vendas_check_enabled', true);
    config()->set('pdv.stale_vendas_threshold_hours', 72);
    config()->set('pdv.stale_vendas_recent_window_days', 7);
    config()->set('pdv.stale_vendas_limit', 100);
});

/**
 * @return array<string, mixed>
 */
function runStaleVendasCheck(array $params = []): array
{
    $params = array_merge(['--json' => true], $params);
    $exitCode = Artisan::call('pdv:stale-vendas-check', $params);
    $decoded = json_decode(trim(Artisan::output()), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON output from pdv:stale-vendas-check.');
    }

    $decoded['_exit_code'] = $exitCode;

    return $decoded;
}

function createPdvVendasTableForStaleCheck(): void
{
    Schema::create('pdv_vendas', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('canal', 20)->default('HIPER_CAIXA');
        $table->unsignedBigInteger('id_operacao');
        $table->string('id_turno', 64)->nullable();
        $table->dateTime('data_hora')->nullable();
        $table->dateTime('last_seen_in_snapshot_at')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });
}

test('stale vendas check returns disabled when feature flag is off', function () {
    config()->set('pdv.stale_vendas_check_enabled', false);

    $result = runStaleVendasCheck();

    expect($result['_exit_code'])->toBe(0);
    expect($result['status'])->toBe('disabled');
    expect($result['enabled'])->toBeFalse();
});

test('stale vendas check returns unavailable when column is missing', function () {
    Schema::create('pdv_vendas', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('store_pdv_id');
    });

    $result = runStaleVendasCheck();

    expect($result['_exit_code'])->toBe(1);
    expect($result['status'])->toBe('unavailable');
});

test('stale vendas check detects records not seen in snapshot', function () {
    createPdvVendasTableForStaleCheck();

    DB::table('pdv_vendas')->insert([
        [
            'store_pdv_id' => 10,
            'store_id' => 1,
            'canal' => 'HIPER_CAIXA',
            'id_operacao' => 1001,
            'id_turno' => 'turno-001',
            'data_hora' => now()->subDays(2)->toDateTimeString(),
            'last_seen_in_snapshot_at' => now()->subHours(80)->toDateTimeString(),
            'created_at' => now()->subDays(2)->toDateTimeString(),
            'updated_at' => now()->subDays(2)->toDateTimeString(),
        ],
        [
            'store_pdv_id' => 10,
            'store_id' => 1,
            'canal' => 'HIPER_LOJA',
            'id_operacao' => 1002,
            'id_turno' => 'turno-002',
            'data_hora' => now()->subHours(4)->toDateTimeString(),
            'last_seen_in_snapshot_at' => now()->subHours(1)->toDateTimeString(),
            'created_at' => now()->subHours(4)->toDateTimeString(),
            'updated_at' => now()->subHours(4)->toDateTimeString(),
        ],
        [
            'store_pdv_id' => 11,
            'store_id' => 2,
            'canal' => 'HIPER_LOJA',
            'id_operacao' => 2001,
            'id_turno' => null,
            'data_hora' => now()->subDays(1)->toDateTimeString(),
            'last_seen_in_snapshot_at' => null,
            'created_at' => now()->subDays(1)->toDateTimeString(),
            'updated_at' => now()->subDays(1)->toDateTimeString(),
        ],
    ]);

    $result = runStaleVendasCheck([
        '--hours' => 72,
        '--recent-days' => 7,
        '--limit' => 10,
    ]);

    expect($result['_exit_code'])->toBe(1);
    expect($result['status'])->toBe('alert');
    expect($result['stale_count'])->toBe(2);
    expect($result['sample'])->toBeArray();
    expect(collect($result['sample'])->pluck('id_operacao')->all())->toContain(1001, 2001);
});

test('stale vendas check ignores old records outside recent window', function () {
    createPdvVendasTableForStaleCheck();

    DB::table('pdv_vendas')->insert([
        'store_pdv_id' => 10,
        'store_id' => 1,
        'canal' => 'HIPER_CAIXA',
        'id_operacao' => 3001,
        'id_turno' => 'turno-3001',
        'data_hora' => now()->subDays(20)->toDateTimeString(),
        'last_seen_in_snapshot_at' => now()->subDays(10)->toDateTimeString(),
        'created_at' => now()->subDays(20)->toDateTimeString(),
        'updated_at' => now()->subDays(20)->toDateTimeString(),
    ]);

    $result = runStaleVendasCheck([
        '--hours' => 72,
        '--recent-days' => 7,
        '--limit' => 10,
    ]);

    expect($result['_exit_code'])->toBe(0);
    expect($result['status'])->toBe('ok');
    expect($result['stale_count'])->toBe(0);
});

