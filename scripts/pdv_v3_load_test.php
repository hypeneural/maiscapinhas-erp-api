<?php

declare(strict_types=1);

/**
 * PDV v3 load test helper (sequential).
 *
 * Example:
 * php scripts/pdv_v3_load_test.php \
 *   --url=http://localhost:8000/api/v1/pdv/sync \
 *   --secret=test-pdv-secret \
 *   --fixture=tests/Fixtures/pdv/v3/mixed_caixa_loja_collision.json \
 *   --stores=15 \
 *   --iterations=20
 */

function arg(string $name, ?string $default = null): ?string
{
    global $argv;
    foreach ($argv as $value) {
        if (!str_starts_with($value, "--{$name}=")) {
            continue;
        }

        return substr($value, strlen("--{$name}="));
    }

    return $default;
}

function fail(string $message): never
{
    fwrite(STDERR, "[error] {$message}\n");
    exit(1);
}

$url = arg('url');
$secret = arg('secret');
$fixturePath = arg('fixture', 'tests/Fixtures/pdv/v3/mixed_caixa_loja_collision.json');
$stores = (int) (arg('stores', '15') ?? 15);
$iterations = (int) (arg('iterations', '20') ?? 20);
$sleepMs = (int) (arg('sleep-ms', '0') ?? 0);

if (!$url) {
    fail('Missing --url');
}
if (!$secret) {
    fail('Missing --secret');
}
if (!is_file($fixturePath)) {
    fail("Fixture not found: {$fixturePath}");
}
if ($stores <= 0 || $iterations <= 0) {
    fail('stores and iterations must be greater than zero.');
}

$rawFixture = file_get_contents($fixturePath);
$basePayload = is_string($rawFixture) ? json_decode($rawFixture, true) : null;
if (!is_array($basePayload)) {
    fail("Invalid fixture JSON: {$fixturePath}");
}

$statusCounters = [];
$latenciesMs = [];
$totalRequests = $stores * $iterations;
$start = microtime(true);

for ($storeOffset = 0; $storeOffset < $stores; $storeOffset++) {
    $pdvStoreId = 1000 + $storeOffset;
    $alias = "load-store-{$pdvStoreId}";

    for ($i = 0; $i < $iterations; $i++) {
        $requestNumber = ($storeOffset * $iterations) + $i + 1;
        $timestamp = time();
        $syncId = sprintf(
            'load-%d-%s-%d',
            $pdvStoreId,
            date('YmdHis'),
            $requestNumber
        );

        $payload = $basePayload;
        $payload['agent']['sent_at'] = gmdate('c');
        $payload['store']['id_ponto_venda'] = $pdvStoreId;
        $payload['store']['alias'] = $alias;
        $payload['store']['nome'] = "Loja Load {$pdvStoreId}";
        $payload['integrity']['sync_id'] = $syncId;
        $payload['window']['from'] = gmdate('c', $timestamp - 600);
        $payload['window']['to'] = gmdate('c', $timestamp);

        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($raw)) {
            fail('Failed to encode payload.');
        }

        $signature = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-PDV-Timestamp: ' . $timestamp,
                'X-PDV-Signature: ' . $signature,
                'X-PDV-Schema-Version: 3.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => $raw,
        ]);

        $requestStart = microtime(true);
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $requestLatencyMs = (microtime(true) - $requestStart) * 1000;
        $latenciesMs[] = $requestLatencyMs;

        if ($httpCode <= 0) {
            $httpCode = 0;
        }
        $statusCounters[$httpCode] = (int) ($statusCounters[$httpCode] ?? 0) + 1;

        curl_close($ch);

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }
}

$durationSec = microtime(true) - $start;
sort($latenciesMs);
$countLatencies = count($latenciesMs);
$avgMs = $countLatencies > 0 ? array_sum($latenciesMs) / $countLatencies : 0;
$p95Index = $countLatencies > 0 ? (int) floor(($countLatencies - 1) * 0.95) : 0;
$p95Ms = $countLatencies > 0 ? $latenciesMs[$p95Index] : 0;

ksort($statusCounters);

echo "PDV v3 load test summary\n";
echo "requests={$totalRequests}\n";
echo "duration_sec=" . number_format($durationSec, 2, '.', '') . "\n";
echo "throughput_rps=" . number_format($totalRequests / max($durationSec, 0.001), 2, '.', '') . "\n";
echo "latency_avg_ms=" . number_format($avgMs, 2, '.', '') . "\n";
echo "latency_p95_ms=" . number_format($p95Ms, 2, '.', '') . "\n";
echo "http_status_counts=" . json_encode($statusCounters, JSON_UNESCAPED_SLASHES) . "\n";
