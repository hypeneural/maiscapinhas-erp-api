<?php

declare(strict_types=1);

use App\Support\Pdv\PdvStoreResolver;
use App\Support\Pdv\PdvUserResolver;
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

    Schema::create('pdv_store_mappings', function ($table) {
        $table->id();
        $table->unsignedBigInteger('pdv_store_id');
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('alias')->nullable();
        $table->string('cnpj')->nullable();
        $table->string('guid_loja', 36)->nullable();
        $table->boolean('active')->default(true);
        $table->timestamps();
    });

    Schema::create('pdv_user_mappings', function ($table) {
        $table->id();
        $table->unsignedBigInteger('pdv_user_id')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('pdv_user_login')->nullable();
        $table->string('guid_usuario', 36)->nullable();
        $table->boolean('is_store_operator')->default(false);
        $table->boolean('active')->default(true);
        $table->integer('confidence')->default(100);
        $table->timestamps();
        $table->string('pdv_user_name')->nullable();
    });
});

test('PdvStoreResolver resolves by GUID', function () {
    DB::table('pdv_store_mappings')->insert([
        'pdv_store_id' => 10,
        'store_id' => 99,
        'alias' => 'loja-test',
        'guid_loja' => 'uuid-1234',
        'active' => true,
    ]);

    $resolver = app(PdvStoreResolver::class);

    // Resolve by GUID
    $result = $resolver->resolve(10, 'wrong-alias', null, null, 'uuid-1234');
    expect($result['status'])->toBe('resolved')
        ->and($result['store_id'])->toBe(99)
        ->and($result['matched_by'])->toBe('guid');

    // Resolve by Alias (fallback if GUID missing/null)
    $result2 = $resolver->resolve(10, 'loja-test', null, null, null);
    expect($result2['status'])->toBe('resolved')
        ->and($result2['store_id'])->toBe(99)
        ->and($result2['matched_by'])->toBe('pdv_store_id_alias');
});

test('PdvStoreResolver prioritizes GUID over Alias mismatch', function () {
    DB::table('pdv_store_mappings')->insert([
        'pdv_store_id' => 20,
        'store_id' => 88,
        'alias' => 'loja-real',
        'guid_loja' => 'uuid-5678',
        'active' => true,
    ]);

    $resolver = app(PdvStoreResolver::class);

    // Payload has correct GUID but wrong Alias
    $result = $resolver->resolve(20, 'loja-wrong', null, null, 'uuid-5678');

    expect($result['status'])->toBe('resolved')
        ->and($result['store_id'])->toBe(88)
        ->and($result['matched_by'])->toBe('guid');
});

test('PdvUserResolver resolves by GUID', function () {
    DB::table('pdv_user_mappings')->insert([
        'pdv_user_id' => 100,
        'user_id' => 500,
        'pdv_user_login' => 'user.old',
        'guid_usuario' => 'user-uuid-1',
        'active' => true,
    ]);

    $resolver = app(PdvUserResolver::class);
    $mappings = $resolver->loadActiveMappings();

    // Resolve by GUID
    $result = $resolver->resolve(100, 'user.wrong', $mappings, 'user-uuid-1');

    expect($result['status'])->toBe('resolved')
        ->and($result['user_id'])->toBe(500);
});

test('PdvUserResolver falls back to Login if GUID not found', function () {
    DB::table('pdv_user_mappings')->insert([
        'pdv_user_id' => 200,
        'user_id' => 600,
        'pdv_user_login' => 'user.login',
        'guid_usuario' => 'user-uuid-2',
        'active' => true,
    ]);

    $resolver = app(PdvUserResolver::class);
    $mappings = $resolver->loadActiveMappings();

    // Resolve by Login (GUID provided but not matching or null)
    $result = $resolver->resolve(200, 'user.login', $mappings, 'wrong-uuid');

    // Note: implementation might not fallback if GUID is provided but not found? 
    // Let's check implementation.
    // Logic: if ($pdvUserGuid !== null && isset($byGuid[$pdvUserGuid])) { return ... }
    // If not set, it continues to ID/Login logic.

    expect($result['status'])->toBe('resolved')
        ->and($result['user_id'])->toBe(600);
});
