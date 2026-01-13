<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

class EvolutionApiClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $instanceName,
    ) {
    }

    /**
     * Get configured HTTP client.
     */
    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders(['apikey' => $this->apiKey])
            ->timeout(20)
            ->retry(2, 250, throw: false);
    }

    /**
     * Get connection state of the instance.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function connectionState(): array
    {
        $res = $this->http()->get("/instance/connectionState/{$this->instanceName}");

        return [
            'ok' => $res->successful(),
            'status' => $res->status(),
            'data' => $res->json(),
        ];
    }

    /**
     * Connect instance (get QR code / pairing code).
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function connect(): array
    {
        $res = $this->http()->get("/instance/connect/{$this->instanceName}");

        return [
            'ok' => $res->successful(),
            'status' => $res->status(),
            'data' => $res->json(),
        ];
    }

    /**
     * Logout/disconnect the instance.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function logout(): array
    {
        $res = $this->http()->delete("/instance/logout/{$this->instanceName}");

        return [
            'ok' => $res->successful(),
            'status' => $res->status(),
            'data' => $res->json(),
        ];
    }

    /**
     * Restart the instance.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function restart(): array
    {
        $res = $this->http()->put("/instance/restart/{$this->instanceName}");

        return [
            'ok' => $res->successful(),
            'status' => $res->status(),
            'data' => $res->json(),
        ];
    }

    /**
     * Send text message.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function sendText(string $number, string $text): array
    {
        $res = $this->http()->post("/message/sendText/{$this->instanceName}", [
            'number' => $number,
            'text' => $text,
        ]);

        return [
            'ok' => $res->successful(),
            'status' => $res->status(),
            'data' => $res->json(),
        ];
    }

    /**
     * Send media message.
     *
     * @param array{number: string, mediatype: string, mimetype: string, media: string, fileName?: string, caption?: string} $payload
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function sendMedia(array $payload): array
    {
        $res = $this->http()->post("/message/sendMedia/{$this->instanceName}", $payload);

        return [
            'ok' => $res->successful(),
            'status' => $res->status(),
            'data' => $res->json(),
        ];
    }

    /**
     * Check if numbers have WhatsApp.
     *
     * @param array<string> $numbers
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function checkWhatsAppNumbers(array $numbers): array
    {
        $res = $this->http()->post("/chat/whatsappNumbers/{$this->instanceName}", [
            'numbers' => $numbers,
        ]);

        return [
            'ok' => $res->successful(),
            'status' => $res->status(),
            'data' => $res->json(),
        ];
    }

    /**
     * Fetch all instances from the server.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function fetchInstances(): array
    {
        $res = $this->http()->get('/instance/fetchInstances');

        return [
            'ok' => $res->successful(),
            'status' => $res->status(),
            'data' => $res->json(),
        ];
    }

    /**
     * Create a new instance on the Evolution server.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function createInstance(array $config): array
    {
        $res = $this->http()->post('/instance/create', array_merge([
            'instanceName' => $this->instanceName,
        ], $config));

        return [
            'ok' => $res->successful(),
            'status' => $res->status(),
            'data' => $res->json(),
        ];
    }

    /**
     * Delete instance from Evolution server.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function deleteInstance(): array
    {
        $res = $this->http()->delete("/instance/delete/{$this->instanceName}");

        return [
            'ok' => $res->successful(),
            'status' => $res->status(),
            'data' => $res->json(),
        ];
    }
}
