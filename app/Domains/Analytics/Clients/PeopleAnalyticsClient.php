<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Clients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PeopleAnalyticsClient
{
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.people_analytics.base_url', 'http://localhost:8000'), '/');
        $this->timeout = (int) config('services.people_analytics.timeout', 30);
    }

    /**
     * Get KPIs for a store and date from FastAPI.
     */
    public function getKpis(string $storeCode, string $date): ?array
    {
        try {
            /** @var Response $response */
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->retry(3, 100)
                ->get('/kpis', [
                    'store_code' => $storeCode,
                    'date' => $date,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('People Analytics API returned non-success status', [
                'status' => $response->status(),
                'store_code' => $storeCode,
                'date' => $date,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('People Analytics API request failed', [
                'error' => $e->getMessage(),
                'store_code' => $storeCode,
                'date' => $date,
            ]);

            return null;
        }
    }

    /**
     * Check if the API is available.
     */
    public function healthCheck(): bool
    {
        try {
            /** @var Response $response */
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->get('/health');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
