<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HiperConnection;
use App\Models\HiperEndpoint;
use App\Services\HiperCookieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class HiperExecuteController extends Controller
{
    public function __construct(
        private readonly HiperCookieService $cookieService,
    ) {
    }

    /**
     * Execute a request against the Hiper ERP using a saved connection + endpoint.
     *
     * POST /api/v1/hiper/execute
     */
    public function execute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'connection_id' => 'required|integer|exists:hiper_connections,id',
            'endpoint_key' => 'required|string|exists:hiper_endpoints,key',
            'params' => 'nullable|array',
            'query' => 'nullable|array',
            'body' => 'nullable|array',
        ]);

        /** @var HiperConnection $connection */
        $connection = HiperConnection::findOrFail($validated['connection_id']);

        /** @var HiperEndpoint $endpoint */
        $endpoint = HiperEndpoint::where('key', $validated['endpoint_key'])->firstOrFail();

        // ── Resolve path with params ──
        $path = $endpoint->path;
        $params = $validated['params'] ?? [];
        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', (string) $value, $path);
        }

        $url = rtrim($connection->base_url, '/') . '/' . ltrim($path, '/');

        // ── Resolve query ──
        $queryParams = array_merge(
            $endpoint->query_template ?? [],
            $validated['query'] ?? []
        );

        // ── Resolve body (POST only) ──
        $bodyPayload = null;
        if (strtoupper($endpoint->method) === 'POST') {
            $bodyPayload = array_replace_recursive(
                $endpoint->body_template ?? [],
                $validated['body'] ?? []
            );
        }

        // ── Cookie header ──
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $cookiesJson = $connection->cookies ?? ['by_domain' => []];
        $cookieResult = $this->cookieService->buildEssentialCookieHeader($cookiesJson, $host);

        // ── Merge headers ──
        $headers = array_merge(
            $connection->default_headers ?? [],
            $endpoint->headers ?? []
        );

        if ($connection->default_referer) {
            $headers['Referer'] = $connection->default_referer;
        }

        $headers['Cookie'] = $cookieResult['cookie'];

        // ── Execute HTTP request ──
        $httpClient = Http::withHeaders($headers)
            ->timeout(30)
            ->withoutVerifying();

        try {
            if (strtoupper($endpoint->method) === 'POST') {
                if (!empty($queryParams)) {
                    $url .= '?' . http_build_query($queryParams);
                }
                $response = $httpClient->post($url, $bodyPayload ?? []);
            } else {
                $response = $httpClient->get($url, $queryParams);
            }

            // Update last_used_at
            $connection->update(['last_used_at' => now()]);

            // Try to parse as JSON, fallback to raw body
            $responseBody = $response->json() ?? $response->body();

            return response()->json([
                'ok' => $response->successful(),
                'status' => $response->status(),
                'url' => $url,
                'missing_cookies' => $cookieResult['missing'],
                'response' => $responseBody,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'url' => $url,
                'missing_cookies' => $cookieResult['missing'],
            ], 502);
        }
    }
}
