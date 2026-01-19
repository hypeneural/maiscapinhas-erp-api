<?php

declare(strict_types=1);

namespace App\Services\Wheel;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * AblyPublisher - Serviço para publicar eventos via Ably REST.
 * 
 * O backend publica eventos; TV e Mobile assinam via WebSocket.
 */
class AblyPublisher
{
    private Client $client;
    private string $ablyKey;
    private string $baseUrl = 'https://rest.ably.io';

    public function __construct()
    {
        $this->ablyKey = config('services.ably.key', env('ABLY_KEY', ''));
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 5,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->ablyKey),
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Publica evento para uma Screen (TV).
     * Canal: wheel:screen:{screen_key}
     */
    public function publishToScreen(string $screenKey, string $eventName, array $payload): bool
    {
        $channel = "wheel:screen:{$screenKey}";
        return $this->publish($channel, $eventName, $payload);
    }

    /**
     * Publica evento para um Player (Mobile).
     * Canal: wheel:player:{player_key}
     */
    public function publishToPlayer(string $playerKey, string $eventName, array $payload): bool
    {
        $channel = "wheel:player:{$playerKey}";
        return $this->publish($channel, $eventName, $payload);
    }

    /**
     * Publica para ambos (Screen e Player).
     */
    public function publishToSessionParticipants(
        string $screenKey,
        string $playerKey,
        string $eventName,
        array $screenPayload,
        array $playerPayload
    ): void {
        $this->publishToScreen($screenKey, $eventName, $screenPayload);
        $this->publishToPlayer($playerKey, $eventName, $playerPayload);
    }

    /**
     * Publica evento para admin (analytics/monitor).
     * Canal: wheel:admin
     */
    public function publishToAdmin(string $eventName, array $payload): bool
    {
        return $this->publish('wheel:admin', $eventName, $payload);
    }

    /**
     * Publica mensagem em um canal.
     */
    private function publish(string $channel, string $eventName, array $payload): bool
    {
        if (empty($this->ablyKey)) {
            Log::warning('Ably key not configured, skipping publish', [
                'channel' => $channel,
                'event' => $eventName,
            ]);
            return false;
        }

        try {
            $data = [
                'name' => $eventName,
                'data' => array_merge($payload, [
                    'event_id' => uniqid('evt_', true),
                    'server_time' => now()->toISOString(),
                ]),
            ];

            $response = $this->client->post("/channels/{$channel}/messages", [
                'json' => $data,
            ]);

            $success = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;

            if ($success) {
                Log::debug('Ably message published', [
                    'channel' => $channel,
                    'event' => $eventName,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Ably publish failed', [
                'channel' => $channel,
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Gera tokenRequest para autenticação de clientes.
     * 
     * @param string $clientId Ex: "screen:screen-tijucas-001" ou "player:player_abc"
     * @param array $capability Canais e permissões
     * @param int $ttlSeconds Tempo de vida do token
     */
    public function createTokenRequest(
        string $clientId,
        array $capability,
        int $ttlSeconds = 3600
    ): array {
        try {
            $response = $this->client->post('/keys/' . $this->getKeyName() . '/requestToken', [
                'json' => [
                    'keyName' => $this->getKeyName(),
                    'ttl' => $ttlSeconds * 1000, // Ably usa ms
                    'clientId' => $clientId,
                    'capability' => json_encode($capability),
                    'timestamp' => now()->getTimestampMs(),
                    'nonce' => bin2hex(random_bytes(16)),
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);

        } catch (\Exception $e) {
            Log::error('Ably token request failed', [
                'clientId' => $clientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Cria token para Screen (TV).
     */
    public function createScreenToken(string $screenKey): array
    {
        return $this->createTokenRequest(
            clientId: "screen:{$screenKey}",
            capability: [
                "wheel:screen:{$screenKey}" => ['subscribe'],
            ],
            ttlSeconds: 3600 * 24 // 24 horas para TV
        );
    }

    /**
     * Cria token para Player (Mobile).
     */
    public function createPlayerToken(string $playerKey): array
    {
        return $this->createTokenRequest(
            clientId: "player:{$playerKey}",
            capability: [
                "wheel:player:{$playerKey}" => ['subscribe'],
            ],
            ttlSeconds: 3600 // 1 hora para player
        );
    }

    private function getKeyName(): string
    {
        // ABLY_KEY format: "appId.keyId:keySecret"
        $parts = explode(':', $this->ablyKey);
        return $parts[0] ?? '';
    }
}
