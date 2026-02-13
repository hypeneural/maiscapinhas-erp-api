<?php

declare(strict_types=1);

use App\Support\Pdv\PdvStoreResolver;
use Illuminate\Database\Schema\Blueprint;
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

    Schema::create('pdv_store_mappings', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('pdv_store_id');
        $table->string('alias', 120)->nullable();
        $table->string('cnpj', 18)->nullable();
        $table->unsignedBigInteger('store_id');
        $table->boolean('active')->default(true);
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });
});

test('resolves by cnpj before alias when both are present', function () {
    DB::table('pdv_store_mappings')->insert([
        [
            'pdv_store_id' => 9,
            'alias' => 'loja-a',
            'cnpj' => '11111111000111',
            'store_id' => 1,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'pdv_store_id' => 9,
            'alias' => 'loja-b',
            'cnpj' => '22222222000122',
            'store_id' => 2,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $resolver = app(PdvStoreResolver::class);
    $result = $resolver->resolve(9, 'loja-a', null, '22222222000122');

    expect($result['status'])->toBe('resolved');
    expect($result['store_id'])->toBe(2);
    expect($result['matched_by'])->toBe('cnpj');
    expect($result['risk_flags'])->toBe([]);
});

test('flags id fallback when resolving by pdv_store_id unique candidate', function () {
    DB::table('pdv_store_mappings')->insert([
        'pdv_store_id' => 13,
        'alias' => 'porto-belo-13',
        'cnpj' => '61063019000333',
        'store_id' => 12,
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resolver = app(PdvStoreResolver::class);
    $result = $resolver->resolve(13, null, null, null);

    expect($result['status'])->toBe('resolved');
    expect($result['store_id'])->toBe(12);
    expect($result['matched_by'])->toBe('pdv_store_id');
    expect($result['risk_flags'])->toContain('store_mapping_by_id_fallback');
});

test('returns ambiguous when pdv_store_id has multiple active mappings and no disambiguator', function () {
    DB::table('pdv_store_mappings')->insert([
        [
            'pdv_store_id' => 6,
            'alias' => 'outlet-6',
            'cnpj' => '29094289000307',
            'store_id' => 3,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'pdv_store_id' => 6,
            'alias' => 'bombinhas-6',
            'cnpj' => '29094289000480',
            'store_id' => 7,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $resolver = app(PdvStoreResolver::class);
    $result = $resolver->resolve(6, null, null, null);

    expect($result['status'])->toBe('ambiguous');
    expect($result['store_id'])->toBeNull();
    expect($result['risk_flags'])->toContain('store_mapping_ambiguous');
    expect($result['candidate_store_ids'])->toMatchArray([3, 7]);
});
