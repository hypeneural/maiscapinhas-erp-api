<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PdvSyncController;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param array<int, mixed> $warnings
 * @return array<int, string>
 */
function invokeWarningRiskFlags(array $warnings): array
{
    $controller = new PdvSyncController();
    $method = new ReflectionMethod($controller, 'warningRiskFlags');
    $method->setAccessible(true);

    /** @var array<int, string> $flags */
    $flags = $method->invoke($controller, $warnings);

    return $flags;
}

test('warningRiskFlags maps GESTAO_DB_FAILURE warning to dedicated risk flag', function () {
    $flags = invokeWarningRiskFlags([
        'GESTAO_DB_FAILURE: [Errno 10061] Connection refused',
    ]);

    expect($flags)->toContain('gestao_db_failure');
});

test('warningRiskFlags ignores warnings that are not gestao database failures', function () {
    $flags = invokeWarningRiskFlags([
        'snapshot_corrected',
        'responsavel_missing',
    ]);

    expect($flags)->not->toContain('gestao_db_failure');
    expect($flags)->toHaveCount(0);
});

test('warningRiskFlags maps vendedor null warning to dedicated risk flag', function () {
    $flags = invokeWarningRiskFlags([
        'Vendedor NULL encontrado em 3 cupom(s)',
    ]);

    expect($flags)->toContain('vendedor_null');
});

test('warningRiskFlags maps payment method null warning to dedicated risk flag', function () {
    $flags = invokeWarningRiskFlags([
        'Meio de pagamento NULL encontrado',
    ]);

    expect($flags)->toContain('meio_pagamento_null');
});

test('warningRiskFlags maps multiple warning categories without duplicates', function () {
    $flags = invokeWarningRiskFlags([
        'GESTAO_DB_FAILURE: timeout',
        'Vendedor NULL encontrado em 2 cupom(s)',
        'Meio de pagamento NULL encontrado',
        'Vendedor NULL encontrado em 5 cupom(s)',
    ]);

    expect($flags)->toContain('gestao_db_failure');
    expect($flags)->toContain('vendedor_null');
    expect($flags)->toContain('meio_pagamento_null');
    expect($flags)->toHaveCount(3);
});
