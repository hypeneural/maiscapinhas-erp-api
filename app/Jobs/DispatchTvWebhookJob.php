<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TvWebhook;
use App\Models\TvWebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DispatchTvWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [15, 60];

    public function __construct(
        public int $deliveryId,
        public bool $rethrowOnFailure = true
    ) {
    }

    public function handle(): void
    {
        $delivery = TvWebhookDelivery::with('webhook')->find($this->deliveryId);
        if (!$delivery) {
            return;
        }

        $attemptNumber = $this->job ? max(1, (int) $this->attempts()) : max(1, (int) $delivery->attempt);
        $timeout = max(2, (int) config('tv.webhook_timeout_seconds', 8));

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'MaisCapinhas-TV-Monitor/1.0',
            'X-TV-Event' => $delivery->event_type,
            'X-TV-Delivery-Id' => $delivery->delivery_uuid,
        ];

        $webhook = $delivery->webhook;
        if ($webhook && is_array($webhook->custom_headers)) {
            foreach ($webhook->custom_headers as $k => $v) {
                $key = trim((string) $k);
                if ($key !== '' && is_scalar($v)) {
                    $headers[$key] = (string) $v;
                }
            }
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($headers)
                ->post($delivery->target_url, $delivery->payload ?? []);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $statusCode = $response->status();
            $bodyExcerpt = Str::limit((string) $response->body(), 1000, '...');

            if ($response->successful()) {
                $delivery->update([
                    'status' => 'delivered',
                    'attempt' => $attemptNumber,
                    'http_status' => $statusCode,
                    'duration_ms' => $durationMs,
                    'response_excerpt' => $bodyExcerpt,
                    'error_message' => null,
                ]);

                if ($webhook) {
                    $webhook->update([
                        'last_triggered_at' => now(),
                        'last_status_code' => $statusCode,
                        'failure_count' => 0,
                    ]);
                }

                return;
            }

            $errorMessage = "HTTP {$statusCode}: " . Str::limit($bodyExcerpt, 300, '...');

            $delivery->update([
                'status' => 'failed',
                'attempt' => $attemptNumber,
                'http_status' => $statusCode,
                'duration_ms' => $durationMs,
                'response_excerpt' => $bodyExcerpt,
                'error_message' => Str::limit($errorMessage, 500, ''),
            ]);

            if ($webhook) {
                $webhook->update([
                    'last_triggered_at' => now(),
                    'last_status_code' => $statusCode,
                    'failure_count' => $webhook->failure_count + 1,
                ]);
            }

            if ($this->rethrowOnFailure && $this->job && $attemptNumber < $this->tries) {
                throw new \RuntimeException($errorMessage);
            }
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            if ($delivery->status !== 'failed') {
                $delivery->update([
                    'status' => 'failed',
                    'attempt' => $attemptNumber,
                    'duration_ms' => $durationMs,
                    'error_message' => Str::limit($e->getMessage(), 500, ''),
                ]);

                if ($webhook) {
                    $webhook->update([
                        'last_triggered_at' => now(),
                        'failure_count' => $webhook->failure_count + 1,
                    ]);
                }
            }

            Log::warning('tv.webhook.delivery_failed', [
                'delivery_id' => $delivery->id,
                'delivery_uuid' => $delivery->delivery_uuid,
                'event_type' => $delivery->event_type,
                'target_url' => $delivery->target_url,
                'attempt' => $attemptNumber,
                'error' => $e->getMessage(),
            ]);

            if ($this->rethrowOnFailure && $this->job && $attemptNumber < $this->tries) {
                throw $e;
            }
        }
    }
}
