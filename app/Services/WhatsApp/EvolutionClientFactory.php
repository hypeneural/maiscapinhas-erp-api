<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\WhatsAppInstance;
use RuntimeException;

class EvolutionClientFactory
{
    /**
     * Create an EvolutionApiClient from a WhatsAppInstance model.
     *
     * @throws RuntimeException if instance has no API key
     */
    public function make(WhatsAppInstance $instance): EvolutionApiClient
    {
        if (empty($instance->api_key)) {
            throw new RuntimeException('Instância sem API Key configurada.');
        }

        return new EvolutionApiClient(
            baseUrl: $instance->base_url,
            apiKey: $instance->api_key, // Decrypted by model getter
            instanceName: $instance->name,
            forcedIp: $instance->forced_ip,
        );
    }

    /**
     * Create client from raw credentials (useful for testing).
     */
    public function fromCredentials(string $baseUrl, string $apiKey, string $instanceName): EvolutionApiClient
    {
        return new EvolutionApiClient(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            instanceName: $instanceName,
        );
    }
}
