<?php

declare(strict_types=1);

use App\Support\Pdv\PdvUserResolver;
use Tests\TestCase;

uses(TestCase::class);

test('resolves by login first and flags mismatch when id points to different mapping', function () {
    $resolver = app(PdvUserResolver::class);

    $mappings = [
        'by_id' => [
            79 => [
                'user_id' => 20,
                'is_store_operator' => false,
                'pdv_user_name' => 'Daren',
                'pdv_user_login' => 'daren',
            ],
            80 => [
                'user_id' => 99,
                'is_store_operator' => false,
                'pdv_user_name' => 'Outro',
                'pdv_user_login' => 'outro',
            ],
        ],
        'by_login' => [
            'daren' => [
                'user_id' => 20,
                'is_store_operator' => false,
                'pdv_user_name' => 'Daren',
                'pdv_user_login' => 'daren',
            ],
        ],
    ];

    $result = $resolver->resolve(80, 'DAREN', $mappings);

    expect($result['status'])->toBe('resolved');
    expect($result['user_id'])->toBe(20);
    expect($result['flags'])->toContain('user_login_mismatch');
});

test('falls back by id when login is missing and marks fallback flag', function () {
    $resolver = app(PdvUserResolver::class);

    $mappings = [
        'by_id' => [
            66 => [
                'user_id' => 24,
                'is_store_operator' => false,
                'pdv_user_name' => 'Larissa',
                'pdv_user_login' => 'larissa',
            ],
        ],
        'by_login' => [],
    ];

    $result = $resolver->resolve(66, 'login-inexistente', $mappings);

    expect($result['status'])->toBe('resolved');
    expect($result['user_id'])->toBe(24);
    expect($result['flags'])->toContain('user_mapping_by_id_fallback');
});

test('returns operator status for operator mappings without user_mapping_missing', function () {
    $resolver = app(PdvUserResolver::class);

    $mappings = [
        'by_id' => [
            87 => [
                'user_id' => null,
                'is_store_operator' => true,
                'pdv_user_name' => 'Loja 12',
                'pdv_user_login' => 'filial12',
            ],
        ],
        'by_login' => [
            'filial12' => [
                'user_id' => null,
                'is_store_operator' => true,
                'pdv_user_name' => 'Loja 12',
                'pdv_user_login' => 'filial12',
            ],
        ],
    ];

    $result = $resolver->resolve(87, 'filial12', $mappings);

    expect($result['status'])->toBe('operator');
    expect($result['user_id'])->toBeNull();
    expect($result['is_store_operator'])->toBeTrue();
    expect($result['flags'])->toBe([]);
});

test('marks user_login_missing when login is provided but not found in mappings', function () {
    $resolver = app(PdvUserResolver::class);

    $result = $resolver->resolve(null, 'nao-mapeado', [
        'by_id' => [],
        'by_login' => [],
    ]);

    expect($result['status'])->toBe('missing');
    expect($result['flags'])->toContain('user_login_missing');
});

