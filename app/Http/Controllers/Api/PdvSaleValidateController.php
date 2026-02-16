<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HiperConnection;
use App\Models\HiperEndpoint;
use App\Services\HiperCookieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Pdv\PdvSaleValidator;
use Illuminate\Support\Facades\Http;

class PdvSaleValidateController extends Controller
{
    /**
     * Valida uma única venda (Legacy/Stand-alone)
     */
    public function validateSingle(Request $request)
    {
        $data = $request->validate([
            'payload' => ['required'], // string JSON ou array
            'canal' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string'],
            'tolerance.total' => ['nullable', 'numeric'],
            'tolerance.start_minus_minutes' => ['nullable', 'integer'],
            'tolerance.end_plus_minutes' => ['nullable', 'integer'],
        ]);

        $validator = new PdvSaleValidator();

        return response()->json(
            $validator->validateFromErpPayload($data)
        );
    }

    /**
     * Valida um lote de vendas (Batch)
     *
     * Aceita duas fontes de dados:
     *   source=json  → Lista[] vem no body (comportamento atual)
     *   source=erp   → Faz request no Hiper via conexão salva + endpoint operacoes.listar
     */
    public function validateBatch(Request $request): JsonResponse
    {
        $source = $request->input('source', 'json');

        if ($source === 'erp') {
            return $this->validateBatchFromErp($request);
        }

        return $this->validateBatchFromJson($request);
    }

    /**
     * Modo JSON — dados vêm direto no body (textarea do front).
     */
    private function validateBatchFromJson(Request $request): JsonResponse
    {
        $data = $request->validate([
            'Lista' => ['required', 'array'],
            'Lista.*' => ['required', 'array'],
            'timezone' => ['nullable', 'string'],
            'tolerance.total' => ['nullable', 'numeric'],
            'tolerance.start_minus_minutes' => ['nullable', 'integer'],
            'tolerance.end_plus_minutes' => ['nullable', 'integer'],
        ]);

        $results = $this->runValidation($data['Lista'], $data);

        return response()->json([
            'source' => 'json',
            'batch_count' => count($results),
            'results' => $results,
        ]);
    }

    /**
     * Modo ERP — busca automaticamente do Hiper Gestão via conexão salva.
     */
    private function validateBatchFromErp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => ['nullable', 'integer', 'exists:hiper_connections,id'],
            'endpoint_key' => ['nullable', 'string'],
            'body' => ['nullable', 'array'],
            'timezone' => ['nullable', 'string'],
            'tolerance.total' => ['nullable', 'numeric'],
            'tolerance.start_minus_minutes' => ['nullable', 'integer'],
            'tolerance.end_plus_minutes' => ['nullable', 'integer'],
        ]);

        // Defaults: primeira conexão ativa + operacoes.listar
        $connection = isset($data['connection_id'])
            ? HiperConnection::findOrFail($data['connection_id'])
            : HiperConnection::where('is_active', true)->firstOrFail();

        $endpointKey = $data['endpoint_key'] ?? 'operacoes.listar';
        $endpoint = HiperEndpoint::where('key', $endpointKey)->firstOrFail();

        // ── Build URL ──
        $url = rtrim($connection->base_url, '/') . '/' . ltrim($endpoint->path, '/');

        // ── Build body (merge template + user overrides) ──
        $bodyPayload = array_replace_recursive(
            $endpoint->body_template ?? [],
            $data['body'] ?? []
        );

        // ── Cookie header ──
        $cookieService = app(HiperCookieService::class);
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $cookiesJson = $connection->cookies ?? ['by_domain' => []];
        $cookieResult = $cookieService->buildEssentialCookieHeader($cookiesJson, $host);

        // ── Headers ──
        $headers = array_merge(
            $connection->default_headers ?? [],
            $endpoint->headers ?? []
        );
        if ($connection->default_referer) {
            $headers['Referer'] = $connection->default_referer;
        }
        $headers['Cookie'] = $cookieResult['cookie'];

        // ── Execute request to ERP ──
        try {
            $httpClient = Http::withHeaders($headers)
                ->timeout(60)
                ->withoutVerifying();

            if (strtoupper($endpoint->method) === 'POST') {
                $response = $httpClient->post($url, $bodyPayload);
            } else {
                $response = $httpClient->get($url, $bodyPayload);
            }

            $connection->update(['last_used_at' => now()]);

            if (!$response->successful()) {
                return response()->json([
                    'ok' => false,
                    'source' => 'erp',
                    'error' => 'ERP retornou status ' . $response->status(),
                    'erp_status' => $response->status(),
                    'url' => $url,
                    'missing_cookies' => $cookieResult['missing'],
                    'erp_body' => $response->json() ?? $response->body(),
                ], 502);
            }

            $erpData = $response->json();

            // O ERP retorna { Lista: [...] } ou diretamente [...]
            $lista = $erpData['Lista'] ?? $erpData ?? [];

            if (!is_array($lista) || empty($lista)) {
                return response()->json([
                    'ok' => false,
                    'source' => 'erp',
                    'error' => 'ERP retornou payload vazio ou sem campo Lista.',
                    'url' => $url,
                    'missing_cookies' => $cookieResult['missing'],
                    'raw_keys' => is_array($erpData) ? array_keys($erpData) : [],
                ], 422);
            }

            $results = $this->runValidation($lista, $data);

            return response()->json([
                'ok' => true,
                'source' => 'erp',
                'batch_count' => count($results),
                'erp_total_returned' => count($lista),
                'url' => $url,
                'missing_cookies' => $cookieResult['missing'],
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'source' => 'erp',
                'error' => $e->getMessage(),
                'url' => $url,
                'missing_cookies' => $cookieResult['missing'],
            ], 502);
        }
    }

    /**
     * Core validation logic — shared between json and erp modes.
     */
    private function runValidation(array $lista, array $options): array
    {
        $validator = new PdvSaleValidator();
        $results = [];

        $globalOptions = [
            'timezone' => $options['timezone'] ?? null,
            'tolerance' => $options['tolerance'] ?? [],
        ];

        foreach ($lista as $item) {
            if (!is_array($item)) {
                continue;
            }

            $singleInput = array_merge($globalOptions, [
                'payload' => $item,
            ]);

            $res = $validator->validateFromErpPayload($singleInput);

            $key = $item['Id'] ?? $item['CodigoDaOperacao'] ?? uniqid();

            $results[] = [
                'input_id' => $key,
                'validation' => $res,
            ];
        }

        return $results;
    }
}
