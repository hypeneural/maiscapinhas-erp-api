<?php

declare(strict_types=1);

use App\Support\Pdv\PdvJsonSchemaValidator;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

function pdvSchemaValidatorPayload(array $overrides = []): array
{
    $base = [
        'schema_version' => '2.0',
        'agent' => [
            'version' => '2.0.0',
            'machine' => 'PDV-STORE-01',
            'sent_at' => '2026-02-10T21:12:56-03:00',
        ],
        'store' => [
            'id_ponto_venda' => 10,
            'nome' => 'Loja 10',
            'alias' => 'loja-10',
        ],
        'window' => [
            'from' => '2026-02-10T20:49:44-03:00',
            'to' => '2026-02-10T21:12:56-03:00',
            'minutes' => 10,
        ],
        'integrity' => [
            'sync_id' => 'schema-validator-test-001',
            'warnings' => [],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

function pdvSchemaValidatorPayloadV3(array $overrides = []): array
{
    $base = [
        'schema_version' => '3.0',
        'event_type' => 'sales',
        'agent' => [
            'version' => '3.0.0',
            'machine' => 'PDV-STORE-01',
            'sent_at' => '2026-02-10T21:12:56-03:00',
        ],
        'store' => [
            'id_ponto_venda' => 10,
            'nome' => 'Loja 10',
            'alias' => 'loja-10',
        ],
        'window' => [
            'from' => '2026-02-10T20:49:44-03:00',
            'to' => '2026-02-10T21:12:56-03:00',
            'minutes' => 10,
        ],
        'vendas' => [[
            'id_operacao' => 12380,
            'canal' => 'HIPER_CAIXA',
            'data_hora' => '2026-02-10T21:12:56-03:00',
            'total' => 129.00,
        ]],
        'ops' => [
            'count' => 1,
            'ids' => [12380],
            'loja_count' => 0,
            'loja_ids' => [],
        ],
        'snapshot_turnos' => [],
        'snapshot_vendas' => [],
        'integrity' => [
            'sync_id' => 'schema-validator-v3-test-001',
            'warnings' => [],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

function setupPdvSchemaValidatorConfig(array $overrides = []): void
{
    $container = new Container();
    Container::setInstance($container);
    $container->instance('config', new Repository(array_replace_recursive([
        'pdv' => [
            'json_schema_validation_enabled' => true,
            'json_schema_files' => [
                '2.0' => __DIR__ . '/../../../docs/schema_v2.0.json',
                '3.0' => __DIR__ . '/../../../docs/schema_v3.0.json',
            ],
        ],
    ], $overrides)));
}

test('skips schema validation when feature flag is disabled', function () {
    setupPdvSchemaValidatorConfig([
        'pdv' => [
            'json_schema_validation_enabled' => false,
        ],
    ]);

    $validator = new PdvJsonSchemaValidator();
    $result = $validator->validate(pdvSchemaValidatorPayload());

    expect($result['status'])->toBe('skipped');
});

test('validates payload successfully when schema validation is enabled', function () {
    setupPdvSchemaValidatorConfig();

    $validator = new PdvJsonSchemaValidator();
    $result = $validator->validate(pdvSchemaValidatorPayload());

    expect($result['status'])->toBe('valid');
    expect($result['errors'])->toBeArray()->toBeEmpty();
});

test('returns invalid when payload has unexpected property', function () {
    setupPdvSchemaValidatorConfig();

    $validator = new PdvJsonSchemaValidator();
    $payload = pdvSchemaValidatorPayload([
        'unexpected_property' => 'x',
    ]);

    $result = $validator->validate($payload);

    expect($result['status'])->toBe('invalid');
    expect($result['errors'])->toBeArray()->not->toBeEmpty();
});

test('validates v3 payload successfully when schema validation is enabled', function () {
    setupPdvSchemaValidatorConfig();

    $validator = new PdvJsonSchemaValidator();
    $result = $validator->validate(pdvSchemaValidatorPayloadV3());

    expect($result['status'])->toBe('valid');
    expect($result['errors'])->toBeArray()->toBeEmpty();
});
