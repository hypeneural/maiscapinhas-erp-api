<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HiperConnection;
use App\Models\HiperEndpoint;
use App\Models\Store;
use App\Services\HiperCookieService;
use App\Services\Pdv\PdvClosureValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

use App\Services\Pdv\PdvClosureUnifiedService;

class PdvClosureValidateController extends Controller
{
    public function __construct(
        private readonly PdvClosureUnifiedService $closureService,
    ) {
    }

    /**
     * Comparação detalhada: ERP Online × Banco Local
     *
     * Busca os dados detalhados de um fechamento de caixa no Hiper ERP (via
     * operacoes.detalhes.fechamento) e compara lado a lado com os dados
     * unificados locais (via PdvClosureUnifiedService).
     *
     * POST /api/v1/pdv/closures/compare-detail
     */
    public function compareDetail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => ['nullable', 'integer', 'exists:hiper_connections,id'],
            'turno_id' => ['required', 'string'],   // UUID do fechamento no ERP
            'closure_uuid' => ['required', 'string'],   // UUID do closure no banco local
        ]);

        // ── 1. Fetch ERP data ──
        $erpResult = $this->fetchErpClosureDetail($data);
        if (!$erpResult['ok']) {
            return response()->json($erpResult, $erpResult['status'] ?? 502);
        }
        $erpData = $erpResult['data'];

        // ── 2. Fetch local unified data ──
        $closureUuid = $data['closure_uuid'];
        $unified = $this->closureService->getUnifiedByClosureUuid($closureUuid);

        if (!$unified) {
            return response()->json([
                'ok' => false,
                'error' => "closure_uuid '{$closureUuid}' não encontrado no banco local.",
            ], 404);
        }

        // ── 3. Store info ──
        $storeInfo = null;
        $storeId = $unified['store_id'] ?? null;
        if ($storeId) {
            $storeRec = \DB::table('stores')->where('id', $storeId)->first(['id', 'name', 'guid']);
            if ($storeRec) {
                $storeInfo = ['id' => $storeRec->id, 'name' => $storeRec->name, 'guid' => $storeRec->guid];
            }
        }

        // ── 4. Normalize ERP data ──
        $erpNormalized = $this->normalizeErpData($erpData);

        // ── 5. Normalize local data ──
        $localNormalized = $this->normalizeLocalData($unified, $storeInfo);

        // ── 6. Build comparison ──
        $comparison = $this->buildDetailedComparison($erpNormalized, $localNormalized);

        return response()->json([
            'ok' => true,
            'erp' => $erpNormalized,
            'local' => $localNormalized,
            'comparison' => $comparison,
        ]);
    }

    /**
     * Fetch closure details from Hiper ERP via saved connection.
     */
    private function fetchErpClosureDetail(array $data): array
    {
        $connection = isset($data['connection_id'])
            ? HiperConnection::findOrFail($data['connection_id'])
            : HiperConnection::where('is_active', true)->firstOrFail();

        $endpoint = HiperEndpoint::where('key', 'operacoes.detalhes.fechamento')->first();
        if (!$endpoint) {
            return ['ok' => false, 'error' => "Endpoint 'operacoes.detalhes.fechamento' não cadastrado. Execute o seeder.", 'status' => 422];
        }

        // Resolve {id} in path
        $path = str_replace('{id}', $data['turno_id'], $endpoint->path);
        $url = rtrim($connection->base_url, '/') . '/' . ltrim($path, '/');

        // Cookie header
        $cookieService = app(HiperCookieService::class);
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $cookiesJson = $connection->cookies ?? ['by_domain' => []];
        $cookieResult = $cookieService->buildEssentialCookieHeader($cookiesJson, $host);

        // Headers
        $headers = array_merge(
            $connection->default_headers ?? [],
            $endpoint->headers ?? []
        );
        if ($connection->default_referer) {
            $headers['Referer'] = $connection->default_referer;
        }
        $headers['Cookie'] = $cookieResult['cookie'];

        try {
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->withoutVerifying()
                ->get($url);

            $connection->update(['last_used_at' => now()]);

            if (!$response->successful()) {
                return [
                    'ok' => false,
                    'error' => 'ERP retornou status ' . $response->status(),
                    'status' => 502,
                    'url' => $url,
                    'missing_cookies' => $cookieResult['missing'],
                    'erp_body' => $response->json() ?? $response->body(),
                ];
            }

            return ['ok' => true, 'data' => $response->json(), 'url' => $url, 'missing_cookies' => $cookieResult['missing']];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 502, 'url' => $url, 'missing_cookies' => $cookieResult['missing']];
        }
    }

    /**
     * Normalize raw ERP response into a flat structure for comparison.
     */
    private function normalizeErpData(array $erp): array
    {
        $meios = [];
        foreach ($erp['MeiosDePagamentos'] ?? [] as $meio) {
            $origens = [];
            foreach ($meio['Origens'] ?? [] as $o) {
                $origens[] = [
                    'origem' => $o['Origem'] ?? null,
                    'entradas_sistema' => (float) ($o['EntradasNoSistema'] ?? 0),
                    'lancamentos_sistema' => (float) ($o['LancamentosNoSistema'] ?? 0),
                    'valor_sistema' => (float) ($o['ValorNoSistema'] ?? 0),
                    'falta' => (float) ($o['FaltaDeCaixa'] ?? 0),
                    'sobra' => (float) ($o['SobraDeCaixa'] ?? 0),
                ];
            }
            $meios[] = [
                'nome' => $meio['Nome'] ?? '?',
                'id_tipo' => $meio['TipoDeMeioDePagamentoId'] ?? null,
                'entradas_sistema' => (float) ($meio['EntradasNoSistema'] ?? 0),
                'lancamentos_sistema' => (float) ($meio['LancamentosNoSistema'] ?? 0),
                'valor_sistema' => (float) ($meio['ValorNoSistema'] ?? 0),
                'falta' => (float) ($meio['FaltaDeCaixa'] ?? 0),
                'sobra' => (float) ($meio['SobraDeCaixa'] ?? 0),
                'valor_gaveta' => (float) ($meio['ValorNaGaveta'] ?? 0),
                'origens' => $origens,
            ];
        }

        return [
            'turno_id' => data_get($erp, 'Turno.Id'),
            'fechado' => (bool) ($erp['CaixaFechado'] ?? false),
            'total_entradas_sistema' => (float) ($erp['TotalDeEntradasNoSistema'] ?? 0),
            'total_lancamentos_sistema' => (float) ($erp['TotalDeLancamentosNoSistema'] ?? 0),
            'total_na_gaveta' => (float) ($erp['TotalNaGaveta'] ?? 0),
            'total_no_sistema' => (float) ($erp['TotalNoSistema'] ?? 0),
            'turno' => [
                'sequencial' => data_get($erp, 'Turno.Sequencial'),
                'loja_id' => data_get($erp, 'Turno.LojaId'),
                'usuario_id' => data_get($erp, 'Turno.UsuarioId'),
                'data' => data_get($erp, 'Turno.Data'),
                'inicio' => data_get($erp, 'Turno.DataEHoraDeInicio'),
                'termino' => data_get($erp, 'Turno.DataEHoraDeTermino'),
                'fechado' => data_get($erp, 'Turno.Fechado'),
            ],
            'meios_pagamento' => $meios,
        ];
    }

    /**
     * Normalize local unified data into a flat structure for comparison.
     */
    private function normalizeLocalData(array $unified, ?array $storeInfo): array
    {
        $porMeio = $unified['pagamentos']['por_meio'] ?? [];

        return [
            'closure_uuid' => $unified['closure_uuid'],
            'store' => $storeInfo,
            'canais_presentes' => $unified['canais_presentes'] ?? [],
            'canal_canonico' => $unified['canal_canonico'] ?? null,
            'sequencial' => $unified['sequencial'] ?? null,
            'operador_guid' => $unified['operador_guid'] ?? null,
            'operador_nome' => $unified['operador_nome'] ?? null,
            'periodo' => $unified['periodo'] ?? null,
            'data_hora_inicio' => $unified['data_hora_inicio'] ?? null,
            'data_hora_termino' => $unified['data_hora_termino'] ?? null,
            'totais' => [
                'entries_expected' => $unified['totais']['entries_expected'] ?? 0,
                'declarado' => $unified['totais']['declarado'] ?? 0,
                'falta' => $unified['totais']['falta'] ?? 0,
                'sobra' => $unified['totais']['sobra'] ?? 0,
                'sistema_caixa' => $unified['totais']['sistema_caixa'] ?? 0,
                'loja_total_sistema_raw' => $unified['totais']['loja_total_sistema_raw'] ?? 0,
                'declared_consistent' => $unified['totais']['declared_consistent'] ?? true,
            ],
            'meios_pagamento' => $porMeio,
        ];
    }

    /**
     * Build the detailed comparison between ERP and local data.
     */
    private function buildDetailedComparison(array $erp, array $local): array
    {
        $erpTotal = $erp['total_entradas_sistema'];
        $localTotal = $local['totais']['entries_expected'];
        $totalDiff = abs($erpTotal - $localTotal);

        // --- Per payment method comparison ---
        $erpMeiosByName = collect($erp['meios_pagamento'])->keyBy('nome');
        $localMeiosByName = collect($local['meios_pagamento'])->keyBy('meio_pagamento');

        // Collect all payment method names
        $allMeios = $erpMeiosByName->keys()->merge($localMeiosByName->keys())->unique();

        $porMeio = [];
        $totalErpFalta = 0;
        $totalErpSobra = 0;
        $totalLocalFalta = 0;
        $totalLocalSobra = 0;

        foreach ($allMeios as $nome) {
            $e = $erpMeiosByName->get($nome);
            $l = $localMeiosByName->get($nome);

            $erpEntradas = $e ? (float) $e['entradas_sistema'] : null;
            $erpFalta = $e ? (float) $e['falta'] : null;
            $erpSobra = $e ? (float) $e['sobra'] : null;
            $localExpected = $l ? (float) $l['entries_expected'] : null;
            $localDeclarado = $l ? (float) $l['declarado'] : null;
            $localFalta = $l ? (float) $l['falta'] : null;
            $localSobra = $l ? (float) $l['sobra'] : null;

            if ($erpFalta !== null)
                $totalErpFalta += $erpFalta;
            if ($erpSobra !== null)
                $totalErpSobra += $erpSobra;
            if ($localFalta !== null)
                $totalLocalFalta += $localFalta;
            if ($localSobra !== null)
                $totalLocalSobra += $localSobra;

            $porMeio[] = [
                'meio' => $nome,
                'erp_entradas' => $erpEntradas,
                'local_expected' => $localExpected,
                'local_declarado' => $localDeclarado,
                'erp_falta' => $erpFalta,
                'local_falta' => $localFalta,
                'erp_sobra' => $erpSobra,
                'local_sobra' => $localSobra,
                'match_entradas' => $erpEntradas !== null && $localExpected !== null && abs($erpEntradas - $localExpected) <= 0.05,
                'match_declarado' => $erpEntradas !== null && $localDeclarado !== null && abs($erpEntradas - $localDeclarado) <= 0.05,
                'match_falta' => $erpFalta !== null && $localFalta !== null && abs($erpFalta - $localFalta) <= 0.05,
                'match_sobra' => $erpSobra !== null && $localSobra !== null && abs($erpSobra - $localSobra) <= 0.05,
                'only_erp' => $e !== null && $l === null,
                'only_local' => $e === null && $l !== null,
            ];
        }

        // --- Operator comparison ---
        $erpGuid = strtolower(trim((string) ($erp['turno']['usuario_id'] ?? '')));
        $localGuid = strtolower(trim((string) ($local['operador_guid'] ?? '')));

        // --- Sequencial comparison ---
        $erpSeq = $erp['turno']['sequencial'] ?? null;
        $localSeq = $local['sequencial'] ?? null;

        return [
            'totais' => [
                'erp_total' => $erpTotal,
                'local_total' => $localTotal,
                'match' => $totalDiff <= 0.05,
                'diff' => round($totalDiff, 2),
            ],
            'falta' => [
                'erp' => round($totalErpFalta, 2),
                'local' => round($totalLocalFalta, 2),
                'match' => abs($totalErpFalta - $totalLocalFalta) <= 0.05,
                'diff' => round(abs($totalErpFalta - $totalLocalFalta), 2),
            ],
            'sobra' => [
                'erp' => round($totalErpSobra, 2),
                'local' => round($totalLocalSobra, 2),
                'match' => abs($totalErpSobra - $totalLocalSobra) <= 0.05,
                'diff' => round(abs($totalErpSobra - $totalLocalSobra), 2),
            ],
            'por_meio' => $porMeio,
            'operador' => [
                'erp_guid' => $erp['turno']['usuario_id'] ?? null,
                'local_guid' => $local['operador_guid'] ?? null,
                'local_nome' => $local['operador_nome'] ?? null,
                'match' => $erpGuid !== '' && $erpGuid === $localGuid,
            ],
            'sequencial' => [
                'erp' => $erpSeq,
                'local' => $localSeq,
                'match' => $erpSeq !== null && $localSeq !== null && (int) $erpSeq === (int) $localSeq,
            ],
            'fechado' => [
                'erp' => $erp['fechado'] ?? false,
                'local' => true, // unified always comes from closed turnos
                'match' => ($erp['fechado'] ?? false) === true,
            ],
        ];
    }

    /**
     * Valida um lote de fechamentos de caixa (Batch)
     *
     * Aceita duas fontes de dados:
     *   source=json  → Lista[] vem no body
     *   source=erp   → Faz request no Hiper via conexão salva + endpoint operacoes.listar.fechamento
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
     * Modo JSON — dados vêm direto no body.
     */
    private function validateBatchFromJson(Request $request): JsonResponse
    {
        $data = $request->validate([
            'Lista' => ['required', 'array'],
            'Lista.*' => ['required', 'array'],
            'timezone' => ['nullable', 'string'],
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
     *
     * Usa endpoint operacoes.listar.fechamento para buscar os últimos 100 fechamentos.
     */
    private function validateBatchFromErp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => ['nullable', 'integer', 'exists:hiper_connections,id'],
            'endpoint_key' => ['nullable', 'string'],
            'body' => ['nullable', 'array'],
            'timezone' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        // Defaults
        $connection = isset($data['connection_id'])
            ? HiperConnection::findOrFail($data['connection_id'])
            : HiperConnection::where('is_active', true)->firstOrFail();

        $endpointKey = $data['endpoint_key'] ?? 'operacoes.listar.fechamento';
        $endpoint = HiperEndpoint::where('key', $endpointKey)->firstOrFail();

        // ── Build URL ──
        $url = rtrim($connection->base_url, '/') . '/' . ltrim($endpoint->path, '/');

        // ── Build body (merge template + user overrides) ──
        $bodyPayload = array_replace_recursive(
            $endpoint->body_template ?? [],
            $data['body'] ?? []
        );

        // ── Inject dynamic dates if user didn't override ──
        if (!isset($data['body']['filtro']['PeriodoInicial'])) {
            $tz = $data['timezone'] ?? 'America/Sao_Paulo';
            $now = Carbon::now($tz);
            $bodyPayload['filtro']['PeriodoInicial'] = $now->copy()->subDays(30)->startOfDay()->format('Y-m-d\\TH:i:sP');
            $bodyPayload['filtro']['PeriodoFinal'] = $now->copy()->endOfDay()->format('Y-m-d\\TH:i:sP');
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

            // ── Filter only "Fechamento de caixa" operations ──
            $closures = array_values(array_filter($lista, function ($item) {
                $tipo = data_get($item, 'TipoDaOperacao', '');
                return stripos($tipo, 'fechamento') !== false
                    || stripos($tipo, 'Fechamento de caixa') !== false;
            }));

            // ── Limit ──
            $limit = $data['limit'] ?? 100;
            $closures = array_slice($closures, 0, $limit);

            $results = $this->runValidation($closures, $data);

            // ── Stats ──
            $found = collect($results)->where('validation.found', true)->count();
            $notFound = collect($results)->where('validation.found', false)->count();

            return response()->json([
                'ok' => true,
                'source' => 'erp',
                'batch_count' => count($results),
                'erp_total_returned' => count($lista),
                'closures_filtered' => count($closures),
                'stats' => [
                    'found' => $found,
                    'not_found' => $notFound,
                ],
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
        $validator = new PdvClosureValidator();
        $timezone = $options['timezone'] ?? 'America/Sao_Paulo';
        $results = [];

        // Pre-load store names by guid (single query)
        $storeGuids = collect($lista)
            ->pluck('Turno.LojaId')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $storeMap = !empty($storeGuids)
            ? Store::whereIn('guid', $storeGuids)->pluck('name', 'guid')->all()
            : [];

        foreach ($lista as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = $item['Id'] ?? $item['CodigoDaOperacao'] ?? uniqid();

            // ── Closure summary ──
            $lojaId = data_get($item, 'Turno.LojaId');
            $storeName = $lojaId ? ($storeMap[$lojaId] ?? null) : null;

            $results[] = [
                'input_id' => $key,
                'closure_summary' => $this->buildClosureSummary($item, $storeName),
                'validation' => $validator->validateClosure($item, $timezone),
            ];
        }

        return $results;
    }

    /**
     * Build a human-readable summary for a single ERP closure item.
     */
    private function buildClosureSummary(array $item, ?string $storeName): array
    {
        // Format date: "17/02/2026 às 16:50"
        $formattedDate = null;
        $rawDate = $item['Data'] ?? null;
        if ($rawDate) {
            try {
                $dt = Carbon::parse($rawDate);
                $formattedDate = $dt->format('d/m/Y') . ' às ' . $dt->format('H:i');
            } catch (\Exception $e) {
                $formattedDate = $rawDate;
            }
        }

        // Shift label: "1º Turno"
        $sequencial = data_get($item, 'Turno.Sequencial');
        $turnoLabel = $sequencial ? "{$sequencial}º Turno" : null;

        $lojaId = data_get($item, 'Turno.LojaId');

        return [
            'codigo' => $item['CodigoDaOperacao'] ?? null,
            'erp_id' => $item['Id'] ?? null,
            'turno_id' => data_get($item, 'Turno.Id'),
            'erp_loja_uuid' => $lojaId,
            'valor' => $item['ValorTotalLiquidoFormatado'] ?? $item['ValorTotalLiquido'] ?? null,
            'valor_liquido' => isset($item['ValorTotalLiquido']) ? (float) $item['ValorTotalLiquido'] : null,
            'valor_bruto' => isset($item['ValorTotalBruto']) ? (float) $item['ValorTotalBruto'] : null,
            'data' => $formattedDate,
            'turno' => $turnoLabel,
            'turno_seq' => $sequencial,
            'loja_erp_id' => $lojaId,
            'loja_nome' => $storeName,
            'found_in_db' => $storeName !== null,
            'tipo' => $item['TipoDaOperacao'] ?? null,
            'cancelada' => $item['Cancelada'] ?? false,
            'concluida' => $item['Concluida'] ?? null,
            'operador_guid' => data_get($item, 'Turno.UsuarioId'),
            'turno_fechado' => data_get($item, 'Turno.Fechado'),
            'turno_inicio' => data_get($item, 'Turno.DataEHoraDeInicio'),
            'turno_termino' => data_get($item, 'Turno.DataEHoraDeTermino'),
        ];
    }
}
