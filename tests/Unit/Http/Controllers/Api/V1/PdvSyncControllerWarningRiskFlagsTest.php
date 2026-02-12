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

