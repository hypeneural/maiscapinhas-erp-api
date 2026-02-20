<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HiperConnection;
use App\Models\HiperEndpoint;
use App\Models\Store;
use App\Services\HiperCookieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Pdv\PdvSaleValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PdvSaleValidateController extends Controller
{
    /**
     * Valida venda(s) — aceita payload manual ou busca do ERP por UUID(s).
     *
     * source=payload (default) → recebe JSON da operação no campo payload
     * source=erp              → recebe operation_ids (UUIDs separados por vírgula)
     *                           e busca cada um via operacoes.detalhes no Hiper
     */
    public function validateSingle(Request $request)
    {
        $source = $request->input('source', 'payload');

        if ($source === 'erp') {
            return $this->validateFromErpDetails($request);
        }

        // ── Modo payload (legado) ──
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
     * Modo ERP para validate single — busca operação(ões) pelo UUID via operacoes.detalhes
     *
     * Aceita operation_ids com um ou mais UUIDs separados por vírgula.
     * Para cada UUID, faz GET /operacoes/{id}/detalhes e valida contra o banco local.
     */
    private function validateFromErpDetails(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operation_ids' => ['required', 'string'], // UUIDs separados por vírgula
            'connection_id' => ['nullable', 'integer', 'exists:hiper_connections,id'],
            'timezone' => ['nullable', 'string'],
            'tolerance.total' => ['nullable', 'numeric'],
            'tolerance.start_minus_minutes' => ['nullable', 'integer'],
            'tolerance.end_plus_minutes' => ['nullable', 'integer'],
        ]);

        // ── Parse operation IDs ──
        $operationIds = array_filter(array_map('trim', explode(',', $data['operation_ids'])));

        if (empty($operationIds)) {
            return response()->json([
                'ok' => false,
                'error' => 'Nenhum operation_id válido informado.',
            ], 422);
        }

        // ── Connection ──
        $connection = isset($data['connection_id'])
            ? HiperConnection::findOrFail($data['connection_id'])
            : HiperConnection::where('is_active', true)->firstOrFail();

        // ── Endpoint operacoes.detalhes ──
        $endpoint = HiperEndpoint::where('key', 'operacoes.detalhes')->firstOrFail();

        // ── Cookie/Headers (shared setup) ──
        $cookieService = app(HiperCookieService::class);
        $baseUrl = rtrim($connection->base_url, '/');
        $host = parse_url($baseUrl, PHP_URL_HOST) ?? '';
        $cookiesJson = $connection->cookies ?? ['by_domain' => []];
        $cookieResult = $cookieService->buildEssentialCookieHeader($cookiesJson, $host);

        $headers = array_merge(
            $connection->default_headers ?? [],
            $endpoint->headers ?? []
        );
        if ($connection->default_referer) {
            $headers['Referer'] = $connection->default_referer;
        }
        $headers['Cookie'] = $cookieResult['cookie'];

        $validator = new PdvSaleValidator();
        $results = [];

        foreach ($operationIds as $opId) {
            // ── Build URL replacing {id} ──
            $path = str_replace('{id}', $opId, $endpoint->path);
            $url = $baseUrl . '/' . ltrim($path, '/');

            try {
                $httpClient = Http::withHeaders($headers)
                    ->timeout(30)
                    ->withoutVerifying();

                $response = (strtoupper($endpoint->method) === 'POST')
                    ? $httpClient->post($url, $endpoint->body_template ?? [])
                    : $httpClient->get($url);

                if (!$response->successful()) {
                    $results[] = [
                        'operation_id' => $opId,
                        'ok' => false,
                        'error' => 'ERP retornou status ' . $response->status(),
                        'erp_status' => $response->status(),
                        'url' => $url,
                    ];
                    continue;
                }

                $erpData = $response->json();

                if (!is_array($erpData) || empty($erpData)) {
                    $results[] = [
                        'operation_id' => $opId,
                        'ok' => false,
                        'error' => 'ERP retornou payload vazio para esta operação.',
                        'url' => $url,
                    ];
                    continue;
                }

                // ── Validate against local DB ──
                $validationResult = $validator->validateFromErpPayload([
                    'payload' => $erpData,
                    'timezone' => $data['timezone'] ?? 'America/Sao_Paulo',
                    'tolerance' => $data['tolerance'] ?? [],
                ]);

                $results[] = array_merge(
                    ['operation_id' => $opId, 'url' => $url],
                    $validationResult
                );
            } catch (\Exception $e) {
                $results[] = [
                    'operation_id' => $opId,
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'url' => $url,
                ];
            }
        }

        $connection->update(['last_used_at' => now()]);

        // Se for um único ID, retorna flat (retrocompatível)
        if (count($operationIds) === 1) {
            return response()->json(array_merge(
                ['source' => 'erp'],
                $results[0] ?? ['ok' => false, 'error' => 'Sem resultados.']
            ));
        }

        return response()->json([
            'source' => 'erp',
            'batch_count' => count($results),
            'missing_cookies' => $cookieResult['missing'],
            'results' => $results,
        ]);
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
            'filters' => ['nullable', 'array'],
            'filters.store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'filters.store_guid' => ['nullable', 'string'],
            'filters.turno_seq' => ['nullable', 'integer', 'min:1', 'max:20'],
            'filters.operation_code' => ['nullable', 'integer', 'min:1'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date'],
            'filters.hour_from' => ['nullable', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'filters.hour_to' => ['nullable', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'filters.value_exact' => ['nullable', 'numeric', 'min:0'],
            'filters.value_min' => ['nullable', 'numeric', 'min:0'],
            'filters.value_max' => ['nullable', 'numeric', 'min:0'],
        ]);

        $timezone = $data['timezone'] ?? 'America/Sao_Paulo';
        $filteredList = $this->applySalesFilters(
            $data['Lista'],
            $data['filters'] ?? [],
            $timezone
        );

        $results = $this->runValidation($filteredList, $data);

        return response()->json([
            'source' => 'json',
            'input_total' => count($data['Lista']),
            'input_total_after_filters' => count($filteredList),
            'batch_count' => count($results),
            'applied_filters' => $this->buildAppliedFiltersMeta($data['filters'] ?? []),
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
            'filters' => ['nullable', 'array'],
            'filters.store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'filters.store_guid' => ['nullable', 'string'],
            'filters.turno_seq' => ['nullable', 'integer', 'min:1', 'max:20'],
            'filters.operation_code' => ['nullable', 'integer', 'min:1'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date'],
            'filters.hour_from' => ['nullable', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'filters.hour_to' => ['nullable', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'filters.value_exact' => ['nullable', 'numeric', 'min:0'],
            'filters.value_min' => ['nullable', 'numeric', 'min:0'],
            'filters.value_max' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Defaults: primeira conexão ativa + operacoes.listar
        $connection = isset($data['connection_id'])
            ? HiperConnection::findOrFail($data['connection_id'])
            : HiperConnection::where('is_active', true)->firstOrFail();

        $endpointKey = $data['endpoint_key'] ?? 'operacoes.listar.vendas';
        $endpoint = HiperEndpoint::where('key', $endpointKey)->firstOrFail();

        // ── Build URL ──
        $url = rtrim($connection->base_url, '/') . '/' . ltrim($endpoint->path, '/');

        // ── Build body (merge template + user overrides) ──
        $bodyPayload = array_replace_recursive(
            $endpoint->body_template ?? [],
            $data['body'] ?? []
        );

        $timezone = $data['timezone'] ?? 'America/Sao_Paulo';
        $filters = $data['filters'] ?? [];

        $filterStoreGuid = $this->resolveStoreGuidFromFilters($filters);
        if ($filterStoreGuid) {
            $bodyPayload['filtro']['LojaId'] = $filterStoreGuid;
        }
        if (isset($filters['operation_code'])) {
            $bodyPayload['filtro']['CodigoDaOperacao'] = (int) $filters['operation_code'];
        }

        // ── Inject dynamic dates if user didn't override ──
        // The body_template may have stale dates; always use fresh dates
        // unless the caller explicitly sent body.filtro.PeriodoInicial/PeriodoFinal
        $periodFrom = $this->resolveFilterDateFrom($filters, $timezone);
        $periodTo = $this->resolveFilterDateTo($filters, $timezone);

        if (!isset($data['body']['filtro']['PeriodoInicial'])) {
            $now = Carbon::now($timezone);

            if (!$periodFrom && !$periodTo) {
                $periodFrom = $now->copy()->subDays(7)->startOfDay();
                $periodTo = $now->copy()->endOfDay();
            } elseif (!$periodFrom && $periodTo) {
                $periodFrom = $periodTo->copy()->startOfDay();
            } elseif ($periodFrom && !$periodTo) {
                $periodTo = $periodFrom->copy()->endOfDay();
            }

            if ($periodFrom && $periodTo && $periodFrom->gt($periodTo)) {
                [$periodFrom, $periodTo] = [$periodTo, $periodFrom];
            }

            if ($periodFrom && $periodTo) {
                $bodyPayload['filtro']['PeriodoInicial'] = $periodFrom->format('Y-m-d\\TH:i:sP');
                $bodyPayload['filtro']['PeriodoFinal'] = $periodTo->format('Y-m-d\\TH:i:sP');
            }
        }

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

            $filteredList = $this->applySalesFilters($lista, $filters, $timezone);
            $results = $this->runValidation($filteredList, $data);

            return response()->json([
                'ok' => true,
                'source' => 'erp',
                'batch_count' => count($results),
                'erp_total_returned' => count($lista),
                'erp_total_after_filters' => count($filteredList),
                'applied_filters' => $this->buildAppliedFiltersMeta($filters),
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

        // Pre-load store names/cities by guid (single query)
        $storeGuids = collect($lista)
            ->pluck('Turno.LojaId')
            ->filter()
            ->map(fn($guid) => strtolower(trim((string) $guid)))
            ->unique()
            ->values()
            ->all();

        $storeMap = [];
        if (!empty($storeGuids)) {
            $stores = Store::query()
                ->whereNotNull('guid')
                ->whereIn(DB::raw('LOWER(guid)'), $storeGuids)
                ->get(['guid', 'name', 'city']);

            foreach ($stores as $store) {
                $storeMap[strtolower((string) $store->guid)] = [
                    'name' => $store->name,
                    'city' => $store->city,
                ];
            }
        }

        foreach ($lista as $item) {
            if (!is_array($item)) {
                continue;
            }

            $singleInput = array_merge($globalOptions, [
                'payload' => $item,
            ]);

            $res = $validator->validateFromErpPayload($singleInput);

            $key = $item['Id'] ?? $item['CodigoDaOperacao'] ?? uniqid();

            // ── Sale summary ──
            $lojaId = $item['Turno']['LojaId'] ?? null;
            $storeMeta = null;
            if ($lojaId) {
                $storeMeta = $storeMap[strtolower(trim((string) $lojaId))] ?? null;
            }

            $results[] = [
                'input_id' => $key,
                'sale_summary' => $this->buildSaleSummary($item, $storeMeta, $res),
                'validation' => $res,
            ];
        }

        return $results;
    }

    /**
     * Build a human-readable summary for a single ERP sale item.
     */
    private function buildSaleSummary(array $item, ?array $storeMeta, array $validation = []): array
    {
        $storeName = $storeMeta['name'] ?? null;
        $storeCity = $storeMeta['city'] ?? null;

        // Format date: "16/02/2026 às 14:50"
        $formattedDate = null;
        if (!empty($item['Data'])) {
            try {
                $dt = Carbon::parse($item['Data']);
                $formattedDate = $dt->format('d/m/Y') . ' às ' . $dt->format('H:i');
            } catch (\Exception $e) {
                $formattedDate = $item['Data'];
            }
        }

        // Shift label: "1º Turno"
        $sequencial = $item['Turno']['Sequencial'] ?? null;
        $turnoLabel = $sequencial ? "{$sequencial}º Turno" : null;

        $lojaId = $item['Turno']['LojaId'] ?? null;
        $foundInDb = (bool) data_get($validation, 'found', false)
            || data_get($validation, 'best_match.pdv_venda_id') !== null;

        return [
            'codigo' => $item['CodigoDaOperacao'] ?? null,
            'erp_id' => $item['Id'] ?? null,
            'erp_loja_uuid' => $lojaId,
            'valor' => $item['ValorTotalLiquidoFormatado'] ?? $item['ValorTotalLiquido'] ?? null,
            'valor_liquido' => isset($item['ValorTotalLiquido']) ? (float) $item['ValorTotalLiquido'] : null,
            'data' => $formattedDate,
            'turno' => $turnoLabel,
            'turno_seq' => $sequencial,
            'turno_id' => $item['Turno']['Id'] ?? null,
            'loja_erp_id' => $lojaId,
            'loja_nome' => $storeName,
            'loja_cidade' => $storeCity,
            'found_in_db' => $foundInDb,
            'cancelada' => $item['Cancelada'] ?? false,
            'concluida' => $item['Concluida'] ?? null,
            'tipo' => $item['TipoDaOperacao'] ?? null,
            'itens' => $item['NumeroDeItens'] ?? null,
        ];
    }

    private function applySalesFilters(array $lista, array $filters, string $timezone): array
    {
        if (empty($filters)) {
            return $lista;
        }

        $storeGuid = $this->resolveStoreGuidFromFilters($filters);
        $turnoSeq = isset($filters['turno_seq']) ? (int) $filters['turno_seq'] : null;
        $operationCode = isset($filters['operation_code']) ? (int) $filters['operation_code'] : null;

        $valueExact = isset($filters['value_exact']) ? (float) $filters['value_exact'] : null;
        $valueMin = isset($filters['value_min']) ? (float) $filters['value_min'] : null;
        $valueMax = isset($filters['value_max']) ? (float) $filters['value_max'] : null;
        if ($valueExact !== null) {
            $valueMin = $valueExact;
            $valueMax = $valueExact;
        }

        $dateFrom = $this->resolveFilterDateFrom($filters, $timezone);
        $dateTo = $this->resolveFilterDateTo($filters, $timezone);
        if ($dateFrom && $dateTo && $dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $hourFrom = $this->parseHourToMinutes($filters['hour_from'] ?? null);
        $hourTo = $this->parseHourToMinutes($filters['hour_to'] ?? null);

        return array_values(array_filter($lista, function ($item) use (
            $storeGuid,
            $turnoSeq,
            $operationCode,
            $valueMin,
            $valueMax,
            $dateFrom,
            $dateTo,
            $hourFrom,
            $hourTo,
            $timezone
        ) {
            if (!is_array($item)) {
                return false;
            }

            $itemStoreGuid = $this->normalizeGuid(
                data_get($item, 'Turno.LojaId')
                ?? data_get($item, 'LojaId')
                ?? data_get($item, 'Loja.LojaId')
            );

            if ($storeGuid && $itemStoreGuid !== $storeGuid) {
                return false;
            }

            if ($turnoSeq !== null && (int) data_get($item, 'Turno.Sequencial', 0) !== $turnoSeq) {
                return false;
            }

            if ($operationCode !== null && (int) data_get($item, 'CodigoDaOperacao', 0) !== $operationCode) {
                return false;
            }

            $total = (float) (data_get($item, 'ValorTotalLiquido') ?? 0);
            if ($valueMin !== null && $total < $valueMin) {
                return false;
            }
            if ($valueMax !== null && $total > $valueMax) {
                return false;
            }

            $itemDateRaw = data_get($item, 'Data');
            $itemDate = null;
            if ($itemDateRaw) {
                try {
                    $itemDate = Carbon::parse((string) $itemDateRaw, $timezone);
                } catch (\Throwable $e) {
                    $itemDate = null;
                }
            }

            if ($dateFrom && (!$itemDate || $itemDate->lt($dateFrom))) {
                return false;
            }

            if ($dateTo && (!$itemDate || $itemDate->gt($dateTo))) {
                return false;
            }

            if ($hourFrom !== null || $hourTo !== null) {
                if (!$itemDate) {
                    return false;
                }

                $minutes = ((int) $itemDate->format('H')) * 60 + (int) $itemDate->format('i');

                if ($hourFrom !== null && $hourTo !== null) {
                    if ($hourFrom <= $hourTo) {
                        if ($minutes < $hourFrom || $minutes > $hourTo) {
                            return false;
                        }
                    } else {
                        // Overnight window (e.g. 22:00 -> 06:00)
                        if ($minutes < $hourFrom && $minutes > $hourTo) {
                            return false;
                        }
                    }
                } elseif ($hourFrom !== null && $minutes < $hourFrom) {
                    return false;
                } elseif ($hourTo !== null && $minutes > $hourTo) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function buildAppliedFiltersMeta(array $filters): array
    {
        $result = [];

        $storeGuid = $this->resolveStoreGuidFromFilters($filters);
        if ($storeGuid) {
            $result['store_guid'] = $storeGuid;
        }
        if (isset($filters['store_id'])) {
            $result['store_id'] = (int) $filters['store_id'];
        }
        if (isset($filters['turno_seq'])) {
            $result['turno_seq'] = (int) $filters['turno_seq'];
        }
        if (isset($filters['operation_code'])) {
            $result['operation_code'] = (int) $filters['operation_code'];
        }
        if (!empty($filters['date_from'])) {
            $result['date_from'] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $result['date_to'] = (string) $filters['date_to'];
        }
        if (!empty($filters['hour_from'])) {
            $result['hour_from'] = (string) $filters['hour_from'];
        }
        if (!empty($filters['hour_to'])) {
            $result['hour_to'] = (string) $filters['hour_to'];
        }
        if (isset($filters['value_exact'])) {
            $result['value_exact'] = (float) $filters['value_exact'];
        }
        if (isset($filters['value_min'])) {
            $result['value_min'] = (float) $filters['value_min'];
        }
        if (isset($filters['value_max'])) {
            $result['value_max'] = (float) $filters['value_max'];
        }

        return $result;
    }

    private function resolveStoreGuidFromFilters(array $filters): ?string
    {
        $storeGuid = $this->normalizeGuid($filters['store_guid'] ?? null);
        if ($storeGuid) {
            return $storeGuid;
        }

        if (isset($filters['store_id'])) {
            $guid = Store::where('id', (int) $filters['store_id'])->value('guid');
            return $this->normalizeGuid($guid);
        }

        return null;
    }

    private function normalizeGuid(?string $guid): ?string
    {
        if ($guid === null) {
            return null;
        }

        $trimmed = strtolower(trim($guid));
        return $trimmed !== '' ? $trimmed : null;
    }

    private function parseHourToMinutes(?string $hour): ?int
    {
        if ($hour === null) {
            return null;
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hour)) {
            return null;
        }

        [$h, $m] = explode(':', $hour);
        return ((int) $h) * 60 + (int) $m;
    }

    private function resolveFilterDateFrom(array $filters, string $timezone): ?Carbon
    {
        if (empty($filters['date_from'])) {
            return null;
        }
        try {
            $raw = trim((string) $filters['date_from']);
            $parsed = Carbon::parse($raw, $timezone);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                return $parsed->startOfDay();
            }

            return $parsed;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveFilterDateTo(array $filters, string $timezone): ?Carbon
    {
        if (empty($filters['date_to'])) {
            return null;
        }
        try {
            $raw = trim((string) $filters['date_to']);
            $parsed = Carbon::parse($raw, $timezone);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                return $parsed->endOfDay();
            }

            return $parsed;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
