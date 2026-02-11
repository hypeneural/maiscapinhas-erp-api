<?php

declare(strict_types=1);

use App\Jobs\ProcessPdvSyncJob;
use App\Models\PdvSync;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    config()->set('pdv.auth_mode', 'hmac');
    config()->set('pdv.hmac_secret', 'test-pdv-secret');
    config()->set('pdv.allow_bearer_fallback', false);
    config()->set('pdv.bearer_token', 'legacy-bearer-token');
    config()->set('pdv.timestamp_mode', 'tolerant');
    config()->set('pdv.timestamp_tolerance_seconds', 600);
    config()->set('pdv.naive_datetime_timezone', 'America/Sao_Paulo');
    config()->set('pdv.queue_name', 'pdv');
    Queue::fake();
});

function pdvPayload(array $overrides = []): array
{
    $base = [
        'schema_version' => '2.0',
        'event_type' => 'sales',
        'agent' => [
            'version' => '2.0.0',
            'machine' => 'PDV-STORE-01',
            'sent_at' => now()->toDateTimeString(),
        ],
        'store' => [
            'id_ponto_venda' => 10,
            'nome' => 'Loja 10',
            'alias' => 'loja-10',
        ],
        'window' => [
            'from' => now()->subMinutes(10)->toDateTimeString(),
            'to' => now()->toDateTimeString(),
            'minutes' => 10,
        ],
        'ops' => [
            'count' => 2,
            'ids' => [1001, 1002],
        ],
        'integrity' => [
            'sync_id' => 'sync-10-20260210-163000',
            'warnings' => [],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

function signedPdvRequest(array $payload, ?int $timestamp = null, ?string $secret = null, array $extraHeaders = [])
{
    $timestamp ??= now()->timestamp;
    $secret ??= (string) config('pdv.hmac_secret');

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    return test()->call(
        'POST',
        '/api/v1/pdv/sync',
        [],
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PDV_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_PDV_SIGNATURE' => $signature,
        ], $extraHeaders),
        $body
    );
}

function bearerPdvRequest(array $payload, string $token = 'legacy-bearer-token')
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return test()->call(
        'POST',
        '/api/v1/pdv/sync',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ],
        $body
    );
}

test('rejects webhook without signature headers', function () {
    $payload = pdvPayload();
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $response = $this->call(
        'POST',
        '/api/v1/pdv/sync',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
        ],
        $body
    );

    $response->assertStatus(401);
});

test('rejects webhook with invalid signature', function () {
    $payload = pdvPayload();
    $timestamp = now()->timestamp;
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $response = $this->call(
        'POST',
        '/api/v1/pdv/sync',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PDV_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_PDV_SIGNATURE' => 'invalid-signature',
        ],
        $body
    );

    $response->assertStatus(403);
});

test('accepts valid webhook and queues processing', function () {
    $payload = pdvPayload();

    $response = signedPdvRequest($payload, null, null, [
        'HTTP_X_PDV_SCHEMA_VERSION' => '2.0',
        'HTTP_X_REQUEST_ID' => 'req-pdv-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.processing_status', 'queued')
        ->assertJsonPath('data.schema_version', '2.0')
        ->assertJsonPath('data.event_type', 'sales')
        ->assertJsonPath('data.request_id', 'req-pdv-001')
        ->assertJsonPath('data.duplicate', false)
        ->assertJsonPath('data.sync_id', 'sync-10-20260210-163000');

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-10-20260210-163000',
        'schema_version' => '2.0',
        'event_type' => 'sales',
        'request_id' => 'req-pdv-001',
        'status' => 'queued',
        'store_pdv_id' => 10,
        'ops_count' => 2,
    ]);
    assertDatabaseCount('pdv_sync_payloads', 1);

    Queue::assertPushed(ProcessPdvSyncJob::class, function (ProcessPdvSyncJob $job) {
        return $job->queue === 'pdv';
    });
});

test('returns 200 duplicate for same sync id', function () {
    $payload = pdvPayload();

    signedPdvRequest($payload)->assertStatus(201);
    signedPdvRequest($payload)->assertStatus(200)
        ->assertJsonPath('data.status', 'duplicate')
        ->assertJsonPath('data.event_type', 'sales')
        ->assertJsonPath('data.duplicate', true);

    assertDatabaseCount('pdv_syncs', 1);
    assertDatabaseCount('pdv_sync_payloads', 1);
});

test('rejects when schema header does not match payload schema_version', function () {
    $payload = pdvPayload([
        'schema_version' => '2.0',
        'integrity' => [
            'sync_id' => 'sync-schema-mismatch-001',
        ],
    ]);

    signedPdvRequest($payload, null, null, [
        'HTTP_X_PDV_SCHEMA_VERSION' => '1.0',
    ])->assertStatus(422);

    assertDatabaseCount('pdv_syncs', 0);
});

test('returns validation error when json schema validation is enabled and payload violates schema', function () {
    config()->set('pdv.json_schema_validation_enabled', true);
    config()->set('pdv.json_schema_files', [
        '2.0' => base_path('docs/schema_v2.0.json'),
    ]);

    $payload = pdvPayload([
        'agent' => [
            'sent_at' => '2026-02-10T21:12:56-03:00',
        ],
        'window' => [
            'from' => '2026-02-10T20:49:44-03:00',
            'to' => '2026-02-10T21:12:56-03:00',
        ],
        'integrity' => [
            'sync_id' => 'sync-schema-runtime-invalid-001',
        ],
    ]);
    $payload['unexpected_property'] = 'x';

    signedPdvRequest($payload)
        ->assertStatus(422)
        ->assertJsonPath('error', 'validation')
        ->assertJsonPath('message', 'Payload does not match JSON schema.');

    assertDatabaseCount('pdv_syncs', 0);
});

test('returns 503 when json schema validator is enabled but schema file is missing', function () {
    config()->set('pdv.json_schema_validation_enabled', true);
    config()->set('pdv.json_schema_files', [
        '2.0' => base_path('docs/schema_v2.0.missing.json'),
    ]);

    $payload = pdvPayload([
        'agent' => [
            'sent_at' => '2026-02-10T21:12:56-03:00',
        ],
        'window' => [
            'from' => '2026-02-10T20:49:44-03:00',
            'to' => '2026-02-10T21:12:56-03:00',
        ],
        'integrity' => [
            'sync_id' => 'sync-schema-runtime-missing-001',
        ],
    ]);

    signedPdvRequest($payload)
        ->assertStatus(503)
        ->assertJsonPath('message', 'Webhook schema validator unavailable.');

    assertDatabaseCount('pdv_syncs', 0);
});

test('strict mode rejects new stale sync', function () {
    config()->set('pdv.timestamp_mode', 'strict');
    config()->set('pdv.timestamp_tolerance_seconds', 600);

    $payload = pdvPayload([
        'integrity' => [
            'sync_id' => 'sync-new-stale-001',
        ],
    ]);
    $oldTimestamp = now()->subMinutes(30)->timestamp;

    signedPdvRequest($payload, $oldTimestamp)->assertStatus(422);

    assertDatabaseCount('pdv_syncs', 0);
});

test('tolerant mode accepts stale new sync and flags timestamp risk', function () {
    config()->set('pdv.timestamp_mode', 'tolerant');
    config()->set('pdv.timestamp_tolerance_seconds', 600);

    $payload = pdvPayload([
        'integrity' => [
            'sync_id' => 'sync-new-stale-tolerant-001',
        ],
    ]);
    $oldTimestamp = now()->subMinutes(30)->timestamp;

    signedPdvRequest($payload, $oldTimestamp)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.processing_status', 'queued')
        ->assertJsonPath('data.timestamp_out_of_window', true);

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-new-stale-tolerant-001',
        'status' => 'queued',
        'timestamp_out_of_window' => 1,
    ]);
});

test('strict mode still returns duplicate 200 even if timestamp is stale', function () {
    config()->set('pdv.timestamp_mode', 'strict');
    config()->set('pdv.timestamp_tolerance_seconds', 600);

    $payload = pdvPayload([
        'integrity' => [
            'sync_id' => 'sync-dup-stale-001',
        ],
    ]);

    signedPdvRequest($payload, now()->timestamp)->assertStatus(201);
    signedPdvRequest($payload, now()->subMinutes(30)->timestamp)
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'duplicate');

    assertDatabaseCount('pdv_syncs', 1);
});

test('accepts bearer fallback when enabled without hmac headers', function () {
    config()->set('pdv.auth_mode', 'auto');
    config()->set('pdv.allow_bearer_fallback', true);
    config()->set('pdv.timestamp_mode', 'strict');

    $payload = pdvPayload([
        'integrity' => [
            'sync_id' => 'sync-bearer-fallback-001',
        ],
    ]);

    bearerPdvRequest($payload)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.processing_status', 'queued')
        ->assertJsonPath('data.auth_mode', 'bearer_fallback')
        ->assertJsonPath('data.timestamp_out_of_window', false);

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-bearer-fallback-001',
        'status' => 'queued',
    ]);
});

test('accepts bearer token when auth mode is bearer', function () {
    config()->set('pdv.auth_mode', 'bearer');
    config()->set('pdv.timestamp_mode', 'strict');

    $payload = pdvPayload([
        'integrity' => [
            'sync_id' => 'sync-bearer-mode-001',
        ],
    ]);

    bearerPdvRequest($payload)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.auth_mode', 'bearer');

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-bearer-mode-001',
        'status' => 'queued',
    ]);
});

test('accepts turno_closure event with empty vendas and zero ops', function () {
    $payload = pdvPayload([
        'event_type' => 'turno_closure',
        'turnos' => [[
            'id_turno' => 'turno-closure-test-001',
            'fechado' => true,
            'data_hora_inicio' => now()->subHours(6)->toDateTimeString(),
            'data_hora_termino' => now()->toDateTimeString(),
            'totais_sistema' => [
                'total' => 100.00,
                'qtd_vendas' => 2,
                'por_pagamento' => [],
            ],
            'fechamento_declarado' => [
                'total' => 95.00,
                'por_pagamento' => [],
            ],
            'falta_caixa' => [
                'total' => 5.00,
                'por_pagamento' => [],
            ],
        ]],
        'vendas' => [],
        'ops' => [
            'count' => 0,
            'ids' => [],
        ],
        'integrity' => [
            'sync_id' => 'sync-turno-closure-001',
        ],
    ]);

    signedPdvRequest($payload)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.event_type', 'turno_closure')
        ->assertJsonPath('data.processing_status', 'queued');

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-turno-closure-001',
        'event_type' => 'turno_closure',
        'ops_count' => 0,
        'status' => 'queued',
    ]);
});

test('unknown event_type falls back to sales and sets risk flag', function () {
    $payload = pdvPayload([
        'event_type' => 'unexpected_mode',
        'integrity' => [
            'sync_id' => 'sync-event-type-fallback-001',
        ],
    ]);

    $response = signedPdvRequest($payload)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.event_type', 'sales')
        ->assertJson(fn ($json) => $json
            ->where('data.status', 'created')
            ->where('data.event_type', 'sales')
            ->has('data.risk_flags')
            ->etc()
        );

    expect($response->json('data.risk_flags'))->toContain('event_type_unknown');

    assertDatabaseHas('pdv_syncs', [
        'sync_id' => 'sync-event-type-fallback-001',
        'event_type' => 'sales',
        'status' => 'queued',
    ]);

    $riskFlags = PdvSync::query()
        ->where('sync_id', 'sync-event-type-fallback-001')
        ->value('risk_flags');

    expect(is_array($riskFlags) ? $riskFlags : [])->toContain('event_type_unknown');
});

test('rejects bearer token when auth mode is bearer and token is invalid', function () {
    config()->set('pdv.auth_mode', 'bearer');

    $payload = pdvPayload([
        'integrity' => [
            'sync_id' => 'sync-bearer-mode-invalid-001',
        ],
    ]);

    bearerPdvRequest($payload, 'invalid-token')->assertStatus(403);
});
