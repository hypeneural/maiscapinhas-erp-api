<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HiperConnection;
use App\Services\HiperCookieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HiperConnectionController extends Controller
{
    public function __construct(
        private readonly HiperCookieService $cookieService,
    ) {
    }

    /**
     * List all connections (without cookies for security).
     */
    public function index(): JsonResponse
    {
        $connections = HiperConnection::select([
            'id',
            'name',
            'base_url',
            'default_referer',
            'is_active',
            'last_used_at',
            'created_at',
            'updated_at',
        ])->orderByDesc('updated_at')->get();

        return response()->json(['ok' => true, 'connections' => $connections]);
    }

    /**
     * Show a single connection (with cookie summary, not raw values).
     */
    public function show(HiperConnection $connection): JsonResponse
    {
        $cookieSummary = null;
        if ($connection->cookies) {
            $byDomain = $connection->cookies['by_domain'] ?? [];
            $cookieSummary = [
                'domains' => array_keys($byDomain),
                'total_cookies' => array_sum(array_map('count', $byDomain)),
                'last_imported_at' => $connection->cookies['last_imported_at'] ?? null,
            ];
        }

        return response()->json([
            'ok' => true,
            'connection' => $connection->makeHidden('cookies'),
            'cookie_summary' => $cookieSummary,
        ]);
    }

    /**
     * Create or update a Hiper ERP connection.
     *
     * POST /api/v1/hiper/connections/upsert
     */
    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'nullable|integer|exists:hiper_connections,id',
            'name' => 'required|string|max:255',
            'base_url' => 'required|url|max:500',
            'default_referer' => 'nullable|string|max:500',
            'default_headers' => 'nullable|array',
        ]);

        $connection = HiperConnection::updateOrCreate(
            ['id' => $validated['id'] ?? null],
            [
                'name' => $validated['name'],
                'base_url' => rtrim($validated['base_url'], '/'),
                'default_referer' => $validated['default_referer'] ?? null,
                'default_headers' => $validated['default_headers'] ?? [
                    'Accept' => 'application/json, text/plain, */*',
                    'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
                    'X-Requested-With' => 'XMLHttpRequest',
                ],
            ]
        );

        return response()->json([
            'ok' => true,
            'connection' => $connection->makeVisible('cookies'),
        ], $request->filled('id') ? 200 : 201);
    }

    /**
     * Import cookies from DevTools TSV, merge into connection.
     *
     * POST /api/v1/hiper/connections/{connection}/import-tsv
     */
    public function importTsv(Request $request, HiperConnection $connection): JsonResponse
    {
        $validated = $request->validate([
            'tsv' => 'required|string|min:10',
        ]);

        $parsed = $this->cookieService->parseTsv($validated['tsv']);

        if (empty($parsed)) {
            return response()->json([
                'ok' => false,
                'error' => 'Não foi possível ler nenhum cookie do TSV fornecido.',
            ], 422);
        }

        $merged = $this->cookieService->mergeIntoJson($parsed, $connection->cookies);

        $connection->update(['cookies' => $merged]);

        // Count domains & cookies
        $domains = array_keys($merged['by_domain'] ?? []);
        $totalCookies = 0;
        foreach ($merged['by_domain'] as $cookies) {
            $totalCookies += count($cookies);
        }

        return response()->json([
            'ok' => true,
            'imported' => count($parsed),
            'total_cookies' => $totalCookies,
            'domains' => $domains,
            'last_imported_at' => $merged['last_imported_at'],
        ]);
    }

    /**
     * Generate essential Cookie header + cURL command for a URL.
     *
     * GET /api/v1/hiper/connections/{connection}/curl?url=...
     */
    public function curl(Request $request, HiperConnection $connection): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url',
        ]);

        $url = $validated['url'];
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        $cookiesJson = $connection->cookies ?? ['by_domain' => []];
        $result = $this->cookieService->buildEssentialCookieHeader($cookiesJson, $host);

        // Build headers map
        $headers = $connection->default_headers ?? [];
        if ($connection->default_referer) {
            $headers['Referer'] = $connection->default_referer;
        }

        $curl = $this->cookieService->buildCurl($url, $headers, $result['cookie']);

        return response()->json([
            'ok' => true,
            'cookie' => $result['cookie'],
            'missing' => $result['missing'],
            'curl' => $curl,
        ]);
    }
}
