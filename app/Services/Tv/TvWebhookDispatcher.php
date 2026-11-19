<?php

declare(strict_types=1);

namespace App\Services\Tv;

use App\Jobs\DispatchTvWebhookJob;
use App\Models\TvWebhook;
use App\Models\TvWebhookDelivery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TvWebhookDispatcher
{
    /**
     * Validate webhook destination URL against SSRF to private/internal networks.
     * Returns null when valid, or a PT-BR validation error string when rejected.
     */
    public function validateTargetUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return 'A URL do webhook informada não é válida.';
        }

        $parsed = parse_url($url);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower((string) ($parsed['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'A URL do webhook deve usar protocolo http:// ou https:// com host válido.';
        }

        $allowPrivate = (bool) config('tv.webhook_allow_private_targets', false)
            || app()->environment(['local', 'testing']);

        if ($allowPrivate) {
            return null;
        }

        if (
            in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'metadata.google.internal'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.localhost')
        ) {
            return 'URLs apontando para hosts internos ou localhost não são permitidas em produção.';
        }

        // If host is directly an IP literal, validate public range
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'IPs privados ou reservados não são permitidos como destino de webhook.';
            }

            return null;
        }

        // Resolve DNS A records and ensure no resolved IP points to private/reserved range
        $ips = @gethostbynamel($host);
        if (is_array($ips)) {
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return "O host '{$host}' resolve para um endereço IP privado/interno não permitido.";
                }
            }
        }

        return null;
    }

    /**
     * Dispatch an outbound TV event to all matching configured webhooks (+ optional .env TV_WEBHOOK_URL).
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, TvWebhookDelivery>
     */
    public function dispatchEvent(
        string $eventType,
        array $payload,
        ?int $storeId = null,
        ?string $screenSlug = null,
        bool $duringBusinessHours = true,
        bool $bypassCooldown = false,
        bool $sync = false
    ): array {
        $deliveries = [];

        // 1. Database-configured active webhooks
        $webhooks = TvWebhook::query()
            ->where('is_active', true)
            ->orderBy('id', 'asc')
            ->get();

        foreach ($webhooks as $webhook) {
            if (!$webhook->subscribesTo($eventType)) {
                continue;
            }
            if (!$webhook->matchesStore($storeId)) {
                continue;
            }
            if ($webhook->only_during_business_hours && !$duringBusinessHours && $eventType !== 'tv.webhook_test') {
                continue;
            }

            $delivery = $this->enqueueDelivery(
                webhook: $webhook,
                targetUrl: $webhook->url,
                eventType: $eventType,
                payload: $payload,
                storeId: $storeId,
                screenSlug: $screenSlug,
                cooldownSeconds: $bypassCooldown ? 0 : (int) $webhook->cooldown_seconds,
                sync: $sync
            );

            if ($delivery) {
                $deliveries[] = $delivery;
            }
        }

        // 2. Optional quick-shortcut .env TV_WEBHOOK_URL (deduplicated against DB URLs)
        $envUrl = trim((string) config('tv.default_webhook_url', ''));
        if ($envUrl !== '') {
            $alreadyMatched = $webhooks->contains(fn (TvWebhook $w) => strcasecmp(trim($w->url), $envUrl) === 0);
            $envEvents = config('tv.default_webhook_events', ['tv.went_offline', 'tv.came_online']);
            $envEvents = is_array($envEvents) ? $envEvents : ['tv.went_offline', 'tv.came_online'];
            $envOnlyHours = (bool) config('tv.default_webhook_only_during_business_hours', false);

            $matchesEvent = in_array('*', $envEvents, true) || in_array($eventType, $envEvents, true) || $eventType === 'tv.webhook_test';
            $matchesHours = !$envOnlyHours || $duringBusinessHours || $eventType === 'tv.webhook_test';

            if (!$alreadyMatched && $matchesEvent && $matchesHours && $this->validateTargetUrl($envUrl) === null) {
                $delivery = $this->enqueueDelivery(
                    webhook: null,
                    targetUrl: $envUrl,
                    eventType: $eventType,
                    payload: $payload,
                    storeId: $storeId,
                    screenSlug: $screenSlug,
                    cooldownSeconds: $bypassCooldown ? 0 : (int) config('tv.webhook_default_cooldown_seconds', 60),
                    sync: $sync
                );

                if ($delivery) {
                    $deliveries[] = $delivery;
                }
            }
        }

        return $deliveries;
    }

    /**
     * Send an immediate synchronous test delivery for a single configured webhook.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendTest(TvWebhook $webhook, array $payload): TvWebhookDelivery
    {
        $delivery = $this->enqueueDelivery(
            webhook: $webhook,
            targetUrl: $webhook->url,
            eventType: 'tv.webhook_test',
            payload: $payload,
            storeId: null,
            screenSlug: null,
            cooldownSeconds: 0,
            sync: true
        );

        return $delivery->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function enqueueDelivery(
        ?TvWebhook $webhook,
        string $targetUrl,
        string $eventType,
        array $payload,
        ?int $storeId,
        ?string $screenSlug,
        int $cooldownSeconds,
        bool $sync
    ): ?TvWebhookDelivery {
        $now = now();
        $deliveryUuid = (string) Str::uuid();
        $payloadWithId = array_merge(['delivery_id' => $deliveryUuid], $payload);

        // Anti-flapping cooldown check (pure DB — zero Redis dependency)
        if ($cooldownSeconds > 0 && $screenSlug !== null) {
            $recentExists = TvWebhookDelivery::query()
                ->where('target_url', $targetUrl)
                ->where('event_type', $eventType)
                ->where('screen_slug', $screenSlug)
                ->whereIn('status', ['delivered', 'pending'])
                ->where('dispatched_at', '>=', $now->copy()->subSeconds($cooldownSeconds))
                ->exists();

            if ($recentExists) {
                return TvWebhookDelivery::create([
                    'delivery_uuid' => $deliveryUuid,
                    'tv_webhook_id' => $webhook?->id,
                    'target_url' => $targetUrl,
                    'event_type' => $eventType,
                    'screen_slug' => $screenSlug,
                    'store_id' => $storeId,
                    'status' => 'skipped_cooldown',
                    'attempt' => 0,
                    'payload' => $payloadWithId,
                    'error_message' => "Ignorado por cooldown anti-flapping ({$cooldownSeconds}s).",
                    'dispatched_at' => $now,
                    'created_at' => $now,
                ]);
            }
        }

        $delivery = TvWebhookDelivery::create([
            'delivery_uuid' => $deliveryUuid,
            'tv_webhook_id' => $webhook?->id,
            'target_url' => $targetUrl,
            'event_type' => $eventType,
            'screen_slug' => $screenSlug,
            'store_id' => $storeId,
            'status' => 'pending',
            'attempt' => 1,
            'payload' => $payloadWithId,
            'dispatched_at' => $now,
            'created_at' => $now,
        ]);

        try {
            if ($sync || config('queue.default') === 'sync') {
                DispatchTvWebhookJob::dispatchSync($delivery->id, false);
            } else {
                DispatchTvWebhookJob::dispatch($delivery->id, true);
            }
        } catch (Throwable $e) {
            // Fallback to immediate non-throwing sync attempt if queue backend (e.g. Redis) is unavailable
            Log::warning('tv.webhook.queue_fallback_sync', [
                'delivery_id' => $delivery->id,
                'error' => $e->getMessage(),
            ]);
            try {
                DispatchTvWebhookJob::dispatchSync($delivery->id, false);
            } catch (Throwable) {
                // Already recorded inside DispatchTvWebhookJob
            }
        }

        return $delivery;
    }
}
