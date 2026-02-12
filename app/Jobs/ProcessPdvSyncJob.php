<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PdvSync;
use App\Support\Pdv\PdvDateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessPdvSyncJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120];

    /** @var array<int, string> */
    private array $runtimeRiskFlags = [];

    private ?bool $hasTurnoMappedUserColumn = null;
    private ?bool $hasItemMappedUserColumn = null;
    private ?bool $hasUserMappingsTable = null;
    private ?bool $hasPdvLojasTable = null;
    private ?bool $hasPdvUsuariosTable = null;
    private ?bool $hasPdvMeiosPagamentoTable = null;
    private ?bool $hasPdvVendasLastSeenColumn = null;
    private const CANAL_HIPER_CAIXA = 'HIPER_CAIXA';
    private const CANAL_HIPER_LOJA = 'HIPER_LOJA';

    public function __construct(
        public int $pdvSyncId
    ) {
        $this->tries = max(1, (int) config('pdv.job_tries', 5));

        $backoff = config('pdv.job_backoff_seconds', [10, 30, 60, 120]);
        if (is_array($backoff)) {
            $normalized = array_values(array_filter(array_map(
                static fn (mixed $value): int => (int) $value,
                $backoff
            ), static fn (int $value): bool => $value >= 0));

            if ($normalized !== []) {
                $this->backoff = $normalized;
            }
        }
    }

    public function uniqueId(): string
    {
        return 'pdv-sync:' . $this->pdvSyncId;
    }

    public function handle(): void
    {
        $startedAt = microtime(true);
        $snapshotTurnosCount = 0;
        $snapshotVendasCount = 0;
        $sync = PdvSync::query()->with('payload')->find($this->pdvSyncId);
        if (!$sync) {
            return;
        }

        // Do not process the same sync twice if it is already final.
        if (in_array($sync->status, [PdvSync::STATUS_PROCESSED], true)) {
            return;
        }

        $lockKey = $this->storeLockKey($sync);
        $lock = Cache::lock($lockKey, (int) config('pdv.store_lock_seconds', 30));

        if (!$lock->get()) {
            $this->release(10);
            return;
        }

        try {
            DB::transaction(function () use ($sync, &$snapshotTurnosCount, &$snapshotVendasCount) {
                $sync->refresh()->load('payload');
                $sync->status = PdvSync::STATUS_PROCESSING;
                $sync->attempts = (int) $sync->attempts + 1;
                $sync->processing_started_at = now();
                $sync->save();

                $payload = $this->decodePayload($sync);
                $context = $this->resolveStoreContext($sync, $payload);
                $this->processMasterData($context['store_pdv_id'], $payload);
                $userMappings = $this->resolveUserMappings($context['store_pdv_id']);
                $turnosCount = is_array(data_get($payload, 'turnos')) ? count((array) data_get($payload, 'turnos')) : 0;
                $vendasCount = is_array(data_get($payload, 'vendas')) ? count((array) data_get($payload, 'vendas')) : 0;

                $this->processTurnos(
                    $sync,
                    $context['store_pdv_id'],
                    $context['store_id'],
                    $userMappings,
                    $payload
                );

                $snapshotTurnosCount = $this->processSnapshotTurnos(
                    $sync,
                    $context['store_pdv_id'],
                    $context['store_id'],
                    $userMappings,
                    $payload
                );

                $this->processVendas(
                    $sync,
                    $context['store_pdv_id'],
                    $context['store_id'],
                    $userMappings,
                    $payload
                );

                $snapshotVendasCount = $this->processSnapshotVendas(
                    $sync,
                    $context['store_pdv_id'],
                    $context['store_id'],
                    $payload
                );

                $this->mergeRuntimeRiskFlags($sync);

                $sync->status = PdvSync::STATUS_PROCESSED;
                $sync->processed_at = now();
                $sync->last_error = null;
                $sync->save();

                Log::info('pdv.sync.process', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'schema_version' => $sync->schema_version,
                    'event_type' => $sync->event_type ?? PdvSync::EVENT_TYPE_SALES,
                    'request_id' => $sync->request_id,
                    'store_pdv_id' => $context['store_pdv_id'],
                    'store_id' => $context['store_id'],
                    'status' => 'processed',
                    'turnos_count' => $turnosCount,
                    'snapshot_turnos_count' => $snapshotTurnosCount,
                    'vendas_count' => $vendasCount,
                    'snapshot_vendas_count' => $snapshotVendasCount,
                ]);
            });
        } catch (Throwable $e) {
            $sync->status = PdvSync::STATUS_FAILED;
            $sync->last_error = Str::limit($e->getMessage(), 2000);
            $sync->save();

            Log::error('pdv.sync.process', [
                'pdv_sync_id' => $sync->id,
                'sync_id' => $sync->sync_id,
                'schema_version' => $sync->schema_version,
                'event_type' => $sync->event_type ?? PdvSync::EVENT_TYPE_SALES,
                'request_id' => $sync->request_id,
                'store_pdv_id' => $sync->store_pdv_id,
                'store_id' => $sync->store_id,
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $lock->release();

            Log::info('pdv.sync.process', [
                'pdv_sync_id' => $sync->id,
                'sync_id' => $sync->sync_id,
                'schema_version' => $sync->schema_version,
                'event_type' => $sync->event_type ?? PdvSync::EVENT_TYPE_SALES,
                'request_id' => $sync->request_id,
                'status' => $sync->status,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(PdvSync $sync): array
    {
        $rawPayload = $sync->payload?->payload;
        if (!is_string($rawPayload) || trim($rawPayload) === '') {
            throw new RuntimeException('Missing RAW payload for PDV sync processing.');
        }

        $decoded = json_decode($rawPayload, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid RAW payload JSON.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{store_pdv_id:int, store_id:int|null}
     */
    private function resolveStoreContext(PdvSync $sync, array $payload): array
    {
        $storePdvId = (int) ($sync->store_pdv_id ?: (int) data_get($payload, 'store.id_ponto_venda', 0));
        if ($storePdvId <= 0) {
            throw new RuntimeException('Missing store.id_ponto_venda for PDV sync.');
        }

        $storeId = $sync->store_id;
        if ($storeId !== null) {
            return [
                'store_pdv_id' => $storePdvId,
                'store_id' => (int) $storeId,
            ];
        }

        $storeId = DB::table('pdv_store_mappings')
            ->where('pdv_store_id', $storePdvId)
            ->where('active', true)
            ->value('store_id');

        $storeId = $storeId !== null ? (int) $storeId : null;
        if ($storeId === null) {
            $this->appendRiskFlags($sync, ['store_mapping_missing']);

            return [
                'store_pdv_id' => $storePdvId,
                'store_id' => null,
            ];
        }

        $sync->store_id = $storeId;
        $sync->save();

        return [
            'store_pdv_id' => $storePdvId,
            'store_id' => $storeId,
        ];
    }

    /**
     * @param array<int, int> $userMappings
     * @param array<string, mixed> $payload
     */
    private function processTurnos(PdvSync $sync, int $storePdvId, ?int $storeId, array $userMappings, array $payload): void
    {
        $turnos = data_get($payload, 'turnos', []);
        if (!is_array($turnos) || $turnos === []) {
            return;
        }

        $now = now();
        $hasOperadorUserId = $this->supportsTurnoMappedUserId();
        $turnoRows = [];
        $pagamentoRows = [];

        foreach ($turnos as $turno) {
            if (!is_array($turno)) {
                continue;
            }

            $idTurno = trim((string) data_get($turno, 'id_turno', ''));
            if ($idTurno === '') {
                Log::warning('Skipping turno without id_turno.', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'store_pdv_id' => $storePdvId,
                ]);
                continue;
            }

            $operadorPdvId = $this->asInt(data_get($turno, 'operador.id_usuario'));
            $operadorUserId = $hasOperadorUserId
                ? $this->resolveMappedUserId($storePdvId, $operadorPdvId, $userMappings)
                : null;

            $turnoRows[] = [
                'store_pdv_id' => $storePdvId,
                'store_id' => $storeId,
                'id_turno' => $idTurno,
                'sequencial' => $this->asInt(data_get($turno, 'sequencial')),
                'fechado' => (bool) data_get($turno, 'fechado', false),
                'data_hora_inicio' => $this->asDateTimeString(data_get($turno, 'data_hora_inicio')),
                'data_hora_termino' => $this->asDateTimeString(data_get($turno, 'data_hora_termino')),
                'duracao_minutos' => $this->asInt(data_get($turno, 'duracao_minutos')),
                'periodo' => $this->asString(data_get($turno, 'periodo')),
                'operador_pdv_id' => $operadorPdvId,
                'operador_nome' => $this->asString(data_get($turno, 'operador.nome')),
                'responsavel_pdv_id' => $this->asInt(data_get($turno, 'responsavel.id_usuario')),
                'responsavel_nome' => $this->asString(data_get($turno, 'responsavel.nome')),
                'total_sistema' => $this->asDecimal(data_get($turno, 'totais_sistema.total'), 2),
                'qtd_vendas_sistema' => max(0, (int) data_get($turno, 'totais_sistema.qtd_vendas', 0)),
                'qtd_vendas' => max(0, (int) data_get($turno, 'qtd_vendas', 0)),
                'total_vendas' => $this->asDecimal(data_get($turno, 'total_vendas', 0), 2),
                'qtd_vendedores' => max(0, (int) data_get($turno, 'qtd_vendedores', 0)),
                'total_declarado' => $this->asDecimalNullable(data_get($turno, 'fechamento_declarado.total'), 2),
                'total_falta' => $this->asDecimalNullable(data_get($turno, 'falta_caixa.total'), 2),
                'last_sync_id' => $sync->sync_id,
                'last_window_to' => $sync->window_to?->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($hasOperadorUserId) {
                $turnoRows[array_key_last($turnoRows)]['operador_user_id'] = $operadorUserId;
            }

            $pagamentoRows = array_merge(
                $pagamentoRows,
                $this->buildTurnoPagamentoRows(
                    $storePdvId,
                    $storeId,
                    $idTurno,
                    'sistema',
                    data_get($turno, 'totais_sistema.por_pagamento', []),
                    $sync,
                    $now
                ),
                $this->buildTurnoPagamentoRows(
                    $storePdvId,
                    $storeId,
                    $idTurno,
                    'declarado',
                    data_get($turno, 'fechamento_declarado.por_pagamento', []),
                    $sync,
                    $now
                ),
                $this->buildTurnoPagamentoRows(
                    $storePdvId,
                    $storeId,
                    $idTurno,
                    'falta',
                    data_get($turno, 'falta_caixa.por_pagamento', []),
                    $sync,
                    $now
                )
            );
        }

        $turnoUpdateColumns = [
            'store_id',
            'sequencial',
            'fechado',
            'data_hora_inicio',
            'data_hora_termino',
            'duracao_minutos',
            'periodo',
            'operador_pdv_id',
            'operador_nome',
            'responsavel_pdv_id',
            'responsavel_nome',
            'total_sistema',
            'qtd_vendas_sistema',
            'qtd_vendas',
            'total_vendas',
            'qtd_vendedores',
            'total_declarado',
            'total_falta',
            'last_sync_id',
            'last_window_to',
            'updated_at',
        ];
        if ($hasOperadorUserId) {
            $turnoUpdateColumns[] = 'operador_user_id';
        }

        $this->upsertRows(
            'pdv_turnos',
            $turnoRows,
            ['store_pdv_id', 'id_turno'],
            $turnoUpdateColumns
        );

        $this->upsertRows(
            'pdv_turno_pagamentos',
            $pagamentoRows,
            ['store_pdv_id', 'id_turno', 'tipo', 'id_finalizador'],
            [
                'store_id',
                'meio_pagamento',
                'total',
                'qtd_vendas',
                'last_sync_id',
                'updated_at',
            ]
        );
    }

    /**
     * @param array<int, int> $userMappings
     * @param array<string, mixed> $payload
     */
    private function processVendas(PdvSync $sync, int $storePdvId, ?int $storeId, array $userMappings, array $payload): void
    {
        $vendas = data_get($payload, 'vendas', []);
        if (!is_array($vendas) || $vendas === []) {
            return;
        }

        $now = now();
        $hasVendedorUserId = $this->supportsItemMappedUserId();
        $vendaRows = [];
        $itemRowsByLineId = [];
        $itemRowsFallback = [];
        $pagamentoRowsByLineId = [];
        $pagamentoRowsFallback = [];

        foreach ($vendas as $venda) {
            if (!is_array($venda)) {
                continue;
            }

            $idOperacao = (int) data_get($venda, 'id_operacao', 0);
            if ($idOperacao <= 0) {
                Log::warning('Skipping venda without id_operacao.', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'store_pdv_id' => $storePdvId,
                ]);
                continue;
            }

            $idTurno = $this->asString(data_get($venda, 'id_turno'));
            $canal = $this->resolveVendaCanal(
                $sync,
                $storePdvId,
                $idOperacao,
                data_get($venda, 'canal')
            );

            $vendaRows[] = [
                'store_pdv_id' => $storePdvId,
                'store_id' => $storeId,
                'canal' => $canal,
                'id_operacao' => $idOperacao,
                'id_turno' => $idTurno,
                'data_hora' => $this->asDateTimeString(data_get($venda, 'data_hora')),
                'total' => $this->asDecimal(data_get($venda, 'total'), 2),
                'sync_id' => $sync->sync_id,
                'last_window_to' => $sync->window_to?->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $itemOccurrences = [];
            $itens = data_get($venda, 'itens', []);
            if (is_array($itens)) {
                foreach ($itens as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $idProduto = $this->asInt(data_get($item, 'id_produto'));
                    $codigoBarras = $this->asString(data_get($item, 'codigo_barras'));
                    $nomeProduto = $this->asString(data_get($item, 'nome'));
                    $qtd = $this->asDecimal(data_get($item, 'qtd', 1), 3);
                    $precoUnit = $this->asDecimal(data_get($item, 'preco_unit'), 2);
                    $totalItem = $this->asDecimal(data_get($item, 'total'), 2);
                    $desconto = $this->asDecimal(data_get($item, 'desconto'), 2);
                    $vendedorPdvId = $this->asInt(data_get($item, 'vendedor.id_usuario'));
                    $vendedorUserId = $hasVendedorUserId
                        ? $this->resolveMappedUserId($storePdvId, $vendedorPdvId, $userMappings)
                        : null;
                    $vendedorNome = $this->asString(data_get($item, 'vendedor.nome'));
                    $lineId = $this->asInt(data_get($item, 'line_id'));
                    $lineId = $lineId !== null && $lineId > 0 ? $lineId : null;
                    $lineNo = $this->resolveLineNumber($item, $index);
                    $lineNoProvided = (int) data_get($item, 'line_no', 0);
                    $fingerprint = $this->itemFingerprint([
                        'id_produto' => $idProduto,
                        'codigo_barras' => $codigoBarras,
                        'nome_produto' => $nomeProduto,
                        'qtd' => $qtd,
                        'preco_unit' => $precoUnit,
                        'total' => $totalItem,
                        'desconto' => $desconto,
                        'vendedor_pdv_id' => $vendedorPdvId,
                        'vendedor_nome' => $vendedorNome,
                    ]);
                    $rowHash = $lineId !== null
                        ? $this->childRowHashByLineId('item', $storePdvId, $lineId)
                        : $this->childRowHash(
                            'item',
                            $storePdvId,
                            $idOperacao,
                            $lineNoProvided > 0 ? $lineNo : null,
                            $fingerprint,
                            $this->nextOccurrence($itemOccurrences, $fingerprint)
                        );

                    $itemRow = [
                        'store_pdv_id' => $storePdvId,
                        'store_id' => $storeId,
                        'canal' => $canal,
                        'id_operacao' => $idOperacao,
                        'line_id' => $lineId,
                        'line_no' => $lineNo,
                        'row_hash' => $rowHash,
                        'id_produto' => $idProduto,
                        'codigo_barras' => $codigoBarras,
                        'nome_produto' => $nomeProduto,
                        'qtd' => $qtd,
                        'preco_unit' => $precoUnit,
                        'total' => $totalItem,
                        'desconto' => $desconto,
                        'vendedor_pdv_id' => $vendedorPdvId,
                        'vendedor_nome' => $vendedorNome,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($hasVendedorUserId) {
                        $itemRow['vendedor_user_id'] = $vendedorUserId;
                    }

                    if ($lineId !== null) {
                        $itemRowsByLineId[] = $itemRow;
                    } else {
                        $itemRowsFallback[] = $itemRow;
                    }
                }
            }

            $paymentOccurrences = [];
            $pagamentos = data_get($venda, 'pagamentos', []);
            if (is_array($pagamentos)) {
                foreach ($pagamentos as $index => $pagamento) {
                    if (!is_array($pagamento)) {
                        continue;
                    }

                    $idFinalizador = max(0, (int) data_get($pagamento, 'id_finalizador', 0));
                    $meioPagamento = $this->asString(data_get($pagamento, 'meio'));
                    $valor = $this->asDecimal(data_get($pagamento, 'valor'), 2);
                    $troco = $this->asDecimal(data_get($pagamento, 'troco'), 2);
                    $parcelas = max(1, (int) data_get($pagamento, 'parcelas', 1));
                    $lineId = $this->asInt(data_get($pagamento, 'line_id'));
                    $lineId = $lineId !== null && $lineId > 0 ? $lineId : null;
                    $lineNo = $this->resolveLineNumber($pagamento, $index);
                    $lineNoProvided = (int) data_get($pagamento, 'line_no', 0);
                    $fingerprint = $this->paymentFingerprint([
                        'id_finalizador' => $idFinalizador,
                        'meio_pagamento' => $meioPagamento,
                        'valor' => $valor,
                        'troco' => $troco,
                        'parcelas' => $parcelas,
                    ]);
                    $rowHash = $lineId !== null
                        ? $this->childRowHashByLineId('payment', $storePdvId, $lineId)
                        : $this->childRowHash(
                            'payment',
                            $storePdvId,
                            $idOperacao,
                            $lineNoProvided > 0 ? $lineNo : null,
                            $fingerprint,
                            $this->nextOccurrence($paymentOccurrences, $fingerprint)
                        );

                    $pagamentoRow = [
                        'store_pdv_id' => $storePdvId,
                        'store_id' => $storeId,
                        'canal' => $canal,
                        'id_operacao' => $idOperacao,
                        'line_id' => $lineId,
                        'line_no' => $lineNo,
                        'row_hash' => $rowHash,
                        'id_finalizador' => $idFinalizador,
                        'meio_pagamento' => $meioPagamento,
                        'valor' => $valor,
                        'troco' => $troco,
                        'parcelas' => $parcelas,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($lineId !== null) {
                        $pagamentoRowsByLineId[] = $pagamentoRow;
                    } else {
                        $pagamentoRowsFallback[] = $pagamentoRow;
                    }
                }
            }
        }

        $this->upsertRows(
            'pdv_vendas',
            $vendaRows,
            ['store_pdv_id', 'canal', 'id_operacao'],
            ['store_id', 'id_turno', 'data_hora', 'total', 'sync_id', 'last_window_to', 'updated_at']
        );

        $itemUpdateColumnsByLineId = [
            'canal',
            'line_id',
            'store_id',
            'id_operacao',
            'line_no',
            'row_hash',
            'id_produto',
            'codigo_barras',
            'nome_produto',
            'qtd',
            'preco_unit',
            'total',
            'desconto',
            'vendedor_pdv_id',
            'vendedor_nome',
            'updated_at',
        ];
        $itemUpdateColumnsFallback = [
            'canal',
            'store_id',
            'line_id',
            'line_no',
            'id_produto',
            'codigo_barras',
            'nome_produto',
            'qtd',
            'preco_unit',
            'total',
            'desconto',
            'vendedor_pdv_id',
            'vendedor_nome',
            'updated_at',
        ];
        if ($hasVendedorUserId) {
            $itemUpdateColumnsByLineId[] = 'vendedor_user_id';
            $itemUpdateColumnsFallback[] = 'vendedor_user_id';
        }

        $this->upsertRows(
            'pdv_venda_itens',
            $itemRowsByLineId,
            ['store_pdv_id', 'canal', 'line_id'],
            $itemUpdateColumnsByLineId
        );

        $this->upsertRows(
            'pdv_venda_itens',
            $itemRowsFallback,
            ['store_pdv_id', 'canal', 'id_operacao', 'row_hash'],
            $itemUpdateColumnsFallback
        );

        $this->upsertRows(
            'pdv_venda_pagamentos',
            $pagamentoRowsByLineId,
            ['store_pdv_id', 'canal', 'line_id'],
            [
                'canal',
                'line_id',
                'store_id',
                'id_operacao',
                'line_no',
                'row_hash',
                'id_finalizador',
                'meio_pagamento',
                'valor',
                'troco',
                'parcelas',
                'updated_at',
            ]
        );

        $this->upsertRows(
            'pdv_venda_pagamentos',
            $pagamentoRowsFallback,
            ['store_pdv_id', 'canal', 'id_operacao', 'row_hash'],
            [
                'canal',
                'store_id',
                'line_id',
                'line_no',
                'id_finalizador',
                'meio_pagamento',
                'valor',
                'troco',
                'parcelas',
                'updated_at',
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processSnapshotVendas(
        PdvSync $sync,
        int $storePdvId,
        ?int $storeId,
        array $payload
    ): int {
        $snapshotVendas = data_get($payload, 'snapshot_vendas', []);
        if (!is_array($snapshotVendas) || $snapshotVendas === []) {
            return 0;
        }

        $now = now();
        $rows = [];
        foreach ($snapshotVendas as $index => $snapshotVenda) {
            if (!is_array($snapshotVenda)) {
                $this->markRuntimeRiskFlag('snapshot_venda_malformed');
                Log::warning('Skipping malformed snapshot_venda entry.', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'store_pdv_id' => $storePdvId,
                    'snapshot_index' => $index,
                ]);
                continue;
            }

            $idOperacao = (int) data_get($snapshotVenda, 'id_operacao', 0);
            if ($idOperacao <= 0) {
                $this->markRuntimeRiskFlag('snapshot_venda_malformed');
                Log::warning('Skipping snapshot_venda without id_operacao.', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'store_pdv_id' => $storePdvId,
                    'snapshot_index' => $index,
                ]);
                continue;
            }

            $canal = $this->resolveVendaCanal(
                $sync,
                $storePdvId,
                $idOperacao,
                data_get($snapshotVenda, 'canal')
            );

            $rows[] = [
                'store_pdv_id' => $storePdvId,
                'store_id' => $storeId,
                'canal' => $canal,
                'id_operacao' => $idOperacao,
                'data_hora_inicio' => $this->asDateTimeString(data_get($snapshotVenda, 'data_hora_inicio')),
                'data_hora_termino' => $this->asDateTimeString(data_get($snapshotVenda, 'data_hora_termino')),
                'duracao_segundos' => $this->asInt(data_get($snapshotVenda, 'duracao_segundos')),
                'id_turno' => $this->asString(data_get($snapshotVenda, 'id_turno')),
                'turno_seq' => $this->asInt(data_get($snapshotVenda, 'turno_seq')),
                'vendedor_pdv_id' => $this->asInt(data_get($snapshotVenda, 'vendedor.id_usuario')),
                'vendedor_nome' => $this->asString(data_get($snapshotVenda, 'vendedor.nome')),
                'qtd_itens' => max(0, (int) data_get($snapshotVenda, 'qtd_itens', 0)),
                'total_itens' => $this->asDecimal(data_get($snapshotVenda, 'total_itens', 0), 2),
                'last_sync_id' => $sync->sync_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->upsertRows(
            'pdv_vendas_resumo',
            $rows,
            ['store_pdv_id', 'canal', 'id_operacao'],
            [
                'store_id',
                'data_hora_inicio',
                'data_hora_termino',
                'duracao_segundos',
                'id_turno',
                'turno_seq',
                'vendedor_pdv_id',
                'vendedor_nome',
                'qtd_itens',
                'total_itens',
                'last_sync_id',
                'updated_at',
            ]
        );

        if ($this->supportsPdvVendasLastSeenColumn()) {
            $this->touchLastSeenInSnapshot($rows, $now);
        }

        return count($rows);
    }

    /**
     * @param array<int, int> $userMappings
     * @param array<string, mixed> $payload
     */
    private function processSnapshotTurnos(
        PdvSync $sync,
        int $storePdvId,
        ?int $storeId,
        array $userMappings,
        array $payload
    ): int {
        $snapshotTurnos = data_get($payload, 'snapshot_turnos', []);
        if (!is_array($snapshotTurnos) || $snapshotTurnos === []) {
            return 0;
        }

        $now = now();
        $hasOperadorUserId = $this->supportsTurnoMappedUserId();
        $rows = [];

        foreach ($snapshotTurnos as $index => $snapshotTurno) {
            if (!is_array($snapshotTurno)) {
                $this->markRuntimeRiskFlag('snapshot_turno_malformed');
                Log::warning('Skipping malformed snapshot_turno entry.', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'store_pdv_id' => $storePdvId,
                    'snapshot_index' => $index,
                ]);
                continue;
            }

            $idTurno = trim((string) data_get($snapshotTurno, 'id_turno', ''));
            if ($idTurno === '') {
                $this->markRuntimeRiskFlag('snapshot_turno_malformed');
                Log::warning('Skipping snapshot_turno without id_turno.', [
                    'pdv_sync_id' => $sync->id,
                    'sync_id' => $sync->sync_id,
                    'store_pdv_id' => $storePdvId,
                    'snapshot_index' => $index,
                ]);
                continue;
            }

            $operadorPdvId = $this->asInt(data_get($snapshotTurno, 'operador.id_usuario'));
            $operadorUserId = $hasOperadorUserId
                ? $this->resolveMappedUserId($storePdvId, $operadorPdvId, $userMappings)
                : null;

            $rows[] = [
                'store_pdv_id' => $storePdvId,
                'store_id' => $storeId,
                'id_turno' => $idTurno,
                'sequencial' => $this->asInt(data_get($snapshotTurno, 'sequencial')),
                'fechado' => (bool) data_get($snapshotTurno, 'fechado', false),
                'data_hora_inicio' => $this->asDateTimeString(data_get($snapshotTurno, 'data_hora_inicio')),
                'data_hora_termino' => $this->asDateTimeString(data_get($snapshotTurno, 'data_hora_termino')),
                'duracao_minutos' => $this->asInt(data_get($snapshotTurno, 'duracao_minutos')),
                'periodo' => $this->asString(data_get($snapshotTurno, 'periodo')),
                'operador_pdv_id' => $operadorPdvId,
                'operador_nome' => $this->asString(data_get($snapshotTurno, 'operador.nome')),
                'responsavel_pdv_id' => $this->asInt(data_get($snapshotTurno, 'responsavel.id_usuario')),
                'responsavel_nome' => $this->asString(data_get($snapshotTurno, 'responsavel.nome')),
                'total_sistema' => $this->asDecimal(data_get($snapshotTurno, 'total_vendas', 0), 2),
                'qtd_vendas_sistema' => max(0, (int) data_get($snapshotTurno, 'qtd_vendas', 0)),
                'qtd_vendas' => max(0, (int) data_get($snapshotTurno, 'qtd_vendas', 0)),
                'total_vendas' => $this->asDecimal(data_get($snapshotTurno, 'total_vendas', 0), 2),
                'qtd_vendedores' => max(0, (int) data_get($snapshotTurno, 'qtd_vendedores', 0)),
                'last_sync_id' => $sync->sync_id,
                'last_window_to' => $sync->window_to?->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($hasOperadorUserId) {
                $rows[array_key_last($rows)]['operador_user_id'] = $operadorUserId;
            }
        }

        $updateColumns = [
            'store_id',
            'sequencial',
            'fechado',
            'data_hora_inicio',
            'data_hora_termino',
            'duracao_minutos',
            'periodo',
            'operador_pdv_id',
            'operador_nome',
            'responsavel_pdv_id',
            'responsavel_nome',
            'total_sistema',
            'qtd_vendas_sistema',
            'qtd_vendas',
            'total_vendas',
            'qtd_vendedores',
            'last_sync_id',
            'last_window_to',
            'updated_at',
        ];
        if ($hasOperadorUserId) {
            $updateColumns[] = 'operador_user_id';
        }

        $this->upsertRows(
            'pdv_turnos',
            $rows,
            ['store_pdv_id', 'id_turno'],
            $updateColumns
        );

        return count($rows);
    }

    /**
     * @param mixed $values
     * @return array<int, array<string, mixed>>
     */
    private function buildTurnoPagamentoRows(
        int $storePdvId,
        ?int $storeId,
        string $idTurno,
        string $tipo,
        mixed $values,
        PdvSync $sync,
        mixed $now
    ): array {
        if (!is_array($values) || $values === []) {
            return [];
        }

        $rows = [];
        foreach ($values as $item) {
            if (!is_array($item)) {
                continue;
            }

            $rows[] = [
                'store_pdv_id' => $storePdvId,
                'store_id' => $storeId,
                'id_turno' => $idTurno,
                'tipo' => $tipo,
                'id_finalizador' => max(0, (int) data_get($item, 'id_finalizador', 0)),
                'meio_pagamento' => $this->asString(data_get($item, 'meio')),
                'total' => $this->asDecimal(data_get($item, 'total'), 2),
                'qtd_vendas' => max(0, (int) data_get($item, 'qtd_vendas', 0)),
                'last_sync_id' => $sync->sync_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $uniqueBy
     * @param array<int, string> $updateColumns
     */
    private function upsertRows(
        string $table,
        array $rows,
        array $uniqueBy,
        array $updateColumns,
        int $chunkSize = 500
    ): void {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $updateColumns);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveLineNumber(array $item, int $index): int
    {
        $lineNo = (int) data_get($item, 'line_no', 0);

        return $lineNo > 0 ? $lineNo : ($index + 1);
    }

    /**
     * @param array<string, int> $occurrences
     */
    private function nextOccurrence(array &$occurrences, string $fingerprint): int
    {
        $occurrences[$fingerprint] = ($occurrences[$fingerprint] ?? 0) + 1;

        return $occurrences[$fingerprint];
    }

    /**
     * @param array<string, mixed> $itemData
     */
    private function itemFingerprint(array $itemData): string
    {
        return hash('sha256', $this->stableJsonEncode($itemData));
    }

    /**
     * @param array<string, mixed> $paymentData
     */
    private function paymentFingerprint(array $paymentData): string
    {
        return hash('sha256', $this->stableJsonEncode($paymentData));
    }

    private function childRowHash(
        string $kind,
        int $storePdvId,
        int $idOperacao,
        ?int $lineNo,
        string $fingerprint,
        int $occurrence
    ): string {
        $lineMarker = $lineNo !== null ? 'line:' . $lineNo : 'fp:' . $fingerprint . ':occ:' . $occurrence;

        return hash('sha256', implode('|', [
            'pdv',
            $kind,
            $storePdvId,
            $idOperacao,
            $lineMarker,
        ]));
    }

    private function childRowHashByLineId(string $kind, int $storePdvId, int $lineId): string
    {
        return hash('sha256', implode('|', [
            'pdv',
            $kind,
            $storePdvId,
            'line_id',
            $lineId,
        ]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stableJsonEncode(array $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            return $encoded;
        }

        return serialize($data);
    }

    private function asDateTimeString(mixed $value): ?string
    {
        $parsed = PdvDateTime::parseToUtc($value);

        return $parsed?->toDateTimeString();
    }

    private function asDecimal(mixed $value, int $scale): string
    {
        return number_format((float) $value, $scale, '.', '');
    }

    private function asDecimalNullable(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->asDecimal($value, $scale);
    }

    private function asInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function asString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = trim((string) $value);

        return $str === '' ? null : $str;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processMasterData(int $storePdvId, array $payload): void
    {
        $now = now();

        if ($this->supportsPdvLojasTable()) {
            $storeNome = $this->asString(data_get($payload, 'store.nome'));
            $storeAlias = $this->asString(data_get($payload, 'store.alias'));
            $existingStore = DB::table('pdv_lojas')
                ->where('id_ponto_venda', $storePdvId)
                ->first(['id', 'nome_padronizado']);

            if ($existingStore) {
                $updates = [
                    'nome_hiper' => $storeNome,
                    'alias' => $storeAlias,
                    'updated_at' => $now,
                ];

                DB::table('pdv_lojas')
                    ->where('id_ponto_venda', $storePdvId)
                    ->update($updates);
            } else {
                DB::table('pdv_lojas')->insert([
                    'id_ponto_venda' => $storePdvId,
                    'nome_padronizado' => $storeNome ?? ('Loja ' . $storePdvId),
                    'nome_hiper' => $storeNome,
                    'alias' => $storeAlias,
                    'ativa' => true,
                    'fonte' => 'HIPER',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if ($this->supportsPdvUsuariosTable()) {
            /** @var array<int, array{nome:?string,papel:string}> $observedUsers */
            $observedUsers = [];
            $observeUser = static function (&$observedUsers, ?int $userId, ?string $userName, string $papel): void {
                if ($userId === null || $userId <= 0) {
                    return;
                }

                $papel = strtoupper(trim($papel)) ?: 'VENDEDOR';
                if (!isset($observedUsers[$userId])) {
                    $observedUsers[$userId] = [
                        'nome' => $userName,
                        'papel' => $papel,
                    ];

                    return;
                }

                if ($observedUsers[$userId]['nome'] === null && $userName !== null) {
                    $observedUsers[$userId]['nome'] = $userName;
                }

                if ($papel === 'OPERADOR') {
                    $observedUsers[$userId]['papel'] = 'OPERADOR';
                }
            };

            $turnos = data_get($payload, 'turnos', []);
            if (is_array($turnos)) {
                foreach ($turnos as $turno) {
                    if (!is_array($turno)) {
                        continue;
                    }

                    $observeUser(
                        $observedUsers,
                        $this->asInt(data_get($turno, 'operador.id_usuario')),
                        $this->asString(data_get($turno, 'operador.nome')),
                        'OPERADOR'
                    );
                    $observeUser(
                        $observedUsers,
                        $this->asInt(data_get($turno, 'responsavel.id_usuario')),
                        $this->asString(data_get($turno, 'responsavel.nome')),
                        'VENDEDOR'
                    );
                }
            }

            $snapshotTurnos = data_get($payload, 'snapshot_turnos', []);
            if (is_array($snapshotTurnos)) {
                foreach ($snapshotTurnos as $turno) {
                    if (!is_array($turno)) {
                        continue;
                    }

                    $observeUser(
                        $observedUsers,
                        $this->asInt(data_get($turno, 'operador.id_usuario')),
                        $this->asString(data_get($turno, 'operador.nome')),
                        'OPERADOR'
                    );
                    $observeUser(
                        $observedUsers,
                        $this->asInt(data_get($turno, 'responsavel.id_usuario')),
                        $this->asString(data_get($turno, 'responsavel.nome')),
                        'VENDEDOR'
                    );
                }
            }

            $vendas = data_get($payload, 'vendas', []);
            if (is_array($vendas)) {
                foreach ($vendas as $venda) {
                    if (!is_array($venda)) {
                        continue;
                    }

                    $itens = data_get($venda, 'itens', []);
                    if (!is_array($itens)) {
                        continue;
                    }

                    foreach ($itens as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $observeUser(
                            $observedUsers,
                            $this->asInt(data_get($item, 'vendedor.id_usuario')),
                            $this->asString(data_get($item, 'vendedor.nome')),
                            'VENDEDOR'
                        );
                    }
                }
            }

            $snapshotVendas = data_get($payload, 'snapshot_vendas', []);
            if (is_array($snapshotVendas)) {
                foreach ($snapshotVendas as $venda) {
                    if (!is_array($venda)) {
                        continue;
                    }

                    $observeUser(
                        $observedUsers,
                        $this->asInt(data_get($venda, 'vendedor.id_usuario')),
                        $this->asString(data_get($venda, 'vendedor.nome')),
                        'VENDEDOR'
                    );
                }
            }

            foreach ($observedUsers as $userId => $userData) {
                $existingUser = DB::table('pdv_usuarios')
                    ->where('id_usuario_hiper', $userId)
                    ->first(['id', 'papel']);

                if ($existingUser) {
                    $updates = [
                        'updated_at' => $now,
                    ];

                    if ($userData['nome'] !== null) {
                        $updates['nome_hiper'] = $userData['nome'];
                    }

                    $existingPapel = strtoupper((string) ($existingUser->papel ?? ''));
                    if ($existingPapel !== 'OPERADOR' && $userData['papel'] === 'OPERADOR') {
                        $updates['papel'] = 'OPERADOR';
                    }

                    DB::table('pdv_usuarios')
                        ->where('id_usuario_hiper', $userId)
                        ->update($updates);
                } else {
                    DB::table('pdv_usuarios')->insert([
                        'id_usuario_hiper' => $userId,
                        'nome_padronizado' => $userData['nome'] ?? ('Usuario ' . $userId),
                        'nome_hiper' => $userData['nome'],
                        'papel' => $userData['papel'],
                        'ativo' => true,
                        'fonte' => 'HIPER',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        if ($this->supportsPdvMeiosPagamentoTable()) {
            /** @var array<int, ?string> $observedPaymentMethods */
            $observedPaymentMethods = [];
            $observePaymentMethod = static function (&$observedPaymentMethods, ?int $idFinalizador, ?string $name): void {
                if ($idFinalizador === null || $idFinalizador <= 0) {
                    return;
                }

                if (!array_key_exists($idFinalizador, $observedPaymentMethods)) {
                    $observedPaymentMethods[$idFinalizador] = $name;
                    return;
                }

                if ($observedPaymentMethods[$idFinalizador] === null && $name !== null) {
                    $observedPaymentMethods[$idFinalizador] = $name;
                }
            };

            $collectTurnoPaymentMethods = function (mixed $turnos) use (&$observedPaymentMethods, $observePaymentMethod): void {
                if (!is_array($turnos)) {
                    return;
                }

                foreach ($turnos as $turno) {
                    if (!is_array($turno)) {
                        continue;
                    }

                    $sections = [
                        data_get($turno, 'totais_sistema.por_pagamento', []),
                        data_get($turno, 'fechamento_declarado.por_pagamento', []),
                        data_get($turno, 'falta_caixa.por_pagamento', []),
                    ];

                    foreach ($sections as $section) {
                        if (!is_array($section)) {
                            continue;
                        }

                        foreach ($section as $payment) {
                            if (!is_array($payment)) {
                                continue;
                            }

                            $observePaymentMethod(
                                $observedPaymentMethods,
                                $this->asInt(data_get($payment, 'id_finalizador')),
                                $this->asString(data_get($payment, 'meio'))
                            );
                        }
                    }
                }
            };

            $collectTurnoPaymentMethods(data_get($payload, 'turnos', []));
            $collectTurnoPaymentMethods(data_get($payload, 'snapshot_turnos', []));

            $vendas = data_get($payload, 'vendas', []);
            if (is_array($vendas)) {
                foreach ($vendas as $venda) {
                    if (!is_array($venda)) {
                        continue;
                    }

                    $payments = data_get($venda, 'pagamentos', []);
                    if (!is_array($payments)) {
                        continue;
                    }

                    foreach ($payments as $payment) {
                        if (!is_array($payment)) {
                            continue;
                        }

                        $observePaymentMethod(
                            $observedPaymentMethods,
                            $this->asInt(data_get($payment, 'id_finalizador')),
                            $this->asString(data_get($payment, 'meio'))
                        );
                    }
                }
            }

            foreach ($observedPaymentMethods as $idFinalizador => $paymentName) {
                $existingPaymentMethod = DB::table('pdv_meios_pagamento')
                    ->where('id_finalizador', $idFinalizador)
                    ->first(['id', 'categoria']);

                $categoria = $this->normalizePaymentCategory($paymentName);

                if ($existingPaymentMethod) {
                    $updates = [
                        'updated_at' => $now,
                    ];

                    if ($paymentName !== null) {
                        $updates['nome_hiper'] = $paymentName;
                    }

                    if (($existingPaymentMethod->categoria === null || trim((string) $existingPaymentMethod->categoria) === '')
                        && $categoria !== null) {
                        $updates['categoria'] = $categoria;
                    }

                    DB::table('pdv_meios_pagamento')
                        ->where('id_finalizador', $idFinalizador)
                        ->update($updates);
                } else {
                    DB::table('pdv_meios_pagamento')->insert([
                        'id_finalizador' => $idFinalizador,
                        'nome_padronizado' => $paymentName ?? ('Finalizador ' . $idFinalizador),
                        'nome_hiper' => $paymentName,
                        'categoria' => $categoria,
                        'ativo' => true,
                        'fonte' => 'HIPER',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function normalizePaymentCategory(?string $paymentName): ?string
    {
        if ($paymentName === null) {
            return null;
        }

        $normalized = Str::upper(Str::ascii($paymentName));
        if (str_contains($normalized, 'PIX')) {
            return 'PIX';
        }
        if (str_contains($normalized, 'DEBIT')) {
            return 'DEBITO';
        }
        if (str_contains($normalized, 'CREDIT')) {
            return 'CREDITO';
        }
        if (str_contains($normalized, 'DINHEIRO') || str_contains($normalized, 'CASH')) {
            return 'DINHEIRO';
        }
        if (str_contains($normalized, 'VALE')) {
            return 'VALE';
        }
        if (str_contains($normalized, 'CHEQUE')) {
            return 'CHEQUE';
        }

        return null;
    }

    private function resolveVendaCanal(PdvSync $sync, int $storePdvId, int $idOperacao, mixed $value): string
    {
        $rawValue = $this->asString($value);
        if ($rawValue === null) {
            return self::CANAL_HIPER_CAIXA;
        }

        $normalized = Str::upper(str_replace('-', '_', $rawValue));
        if (in_array($normalized, [self::CANAL_HIPER_CAIXA, self::CANAL_HIPER_LOJA], true)) {
            return $normalized;
        }

        $this->markRuntimeRiskFlag('venda_canal_invalid');

        Log::warning('pdv.sync.venda_canal_invalid', [
            'pdv_sync_id' => $sync->id,
            'sync_id' => $sync->sync_id,
            'store_pdv_id' => $storePdvId,
            'id_operacao' => $idOperacao,
            'canal' => $rawValue,
        ]);

        return self::CANAL_HIPER_CAIXA;
    }

    /**
     * @return array<int, int> [pdv_user_id => user_id]
     */
    private function resolveUserMappings(int $storePdvId): array
    {
        if (!$this->supportsUserMappingsTable()) {
            return [];
        }

        return DB::table('pdv_user_mappings')
            ->where('store_pdv_id', $storePdvId)
            ->where('active', true)
            ->pluck('user_id', 'pdv_user_id')
            ->mapWithKeys(static fn (mixed $userId, mixed $pdvUserId): array => [
                (int) $pdvUserId => (int) $userId,
            ])
            ->all();
    }

    /**
     * @param array<int, int> $userMappings
     */
    private function resolveMappedUserId(int $storePdvId, ?int $pdvUserId, array $userMappings): ?int
    {
        if ($pdvUserId === null || $pdvUserId <= 0) {
            return null;
        }

        $mapped = $userMappings[$pdvUserId] ?? null;
        if ($mapped !== null && $mapped > 0) {
            return $mapped;
        }

        $this->markRuntimeRiskFlag('user_mapping_missing');

        Log::warning('pdv.sync.user_mapping_missing', [
            'pdv_sync_id' => $this->pdvSyncId,
            'store_pdv_id' => $storePdvId,
            'pdv_user_id' => $pdvUserId,
        ]);

        return null;
    }

    private function markRuntimeRiskFlag(string $flag): void
    {
        $flag = trim($flag);
        if ($flag === '') {
            return;
        }

        $this->runtimeRiskFlags[$flag] = $flag;
    }

    private function mergeRuntimeRiskFlags(PdvSync $sync): void
    {
        if ($this->runtimeRiskFlags === []) {
            return;
        }

        $this->appendRiskFlags($sync, array_values($this->runtimeRiskFlags));
        $this->runtimeRiskFlags = [];
    }

    private function supportsTurnoMappedUserId(): bool
    {
        if ($this->hasTurnoMappedUserColumn !== null) {
            return $this->hasTurnoMappedUserColumn;
        }

        return $this->hasTurnoMappedUserColumn = Schema::hasColumn('pdv_turnos', 'operador_user_id');
    }

    private function supportsItemMappedUserId(): bool
    {
        if ($this->hasItemMappedUserColumn !== null) {
            return $this->hasItemMappedUserColumn;
        }

        return $this->hasItemMappedUserColumn = Schema::hasColumn('pdv_venda_itens', 'vendedor_user_id');
    }

    private function supportsUserMappingsTable(): bool
    {
        if ($this->hasUserMappingsTable !== null) {
            return $this->hasUserMappingsTable;
        }

        return $this->hasUserMappingsTable = Schema::hasTable('pdv_user_mappings');
    }

    private function supportsPdvLojasTable(): bool
    {
        if ($this->hasPdvLojasTable !== null) {
            return $this->hasPdvLojasTable;
        }

        return $this->hasPdvLojasTable = Schema::hasTable('pdv_lojas');
    }

    private function supportsPdvUsuariosTable(): bool
    {
        if ($this->hasPdvUsuariosTable !== null) {
            return $this->hasPdvUsuariosTable;
        }

        return $this->hasPdvUsuariosTable = Schema::hasTable('pdv_usuarios');
    }

    private function supportsPdvMeiosPagamentoTable(): bool
    {
        if ($this->hasPdvMeiosPagamentoTable !== null) {
            return $this->hasPdvMeiosPagamentoTable;
        }

        return $this->hasPdvMeiosPagamentoTable = Schema::hasTable('pdv_meios_pagamento');
    }

    /**
     * @param array<int, array<string, mixed>> $snapshotRows
     */
    private function touchLastSeenInSnapshot(array $snapshotRows, \DateTimeInterface $seenAt): void
    {
        /** @var array<string, array{store_pdv_id:int,canal:string,id_operacao:int}> $keys */
        $keys = [];
        foreach ($snapshotRows as $row) {
            $storePdvId = (int) ($row['store_pdv_id'] ?? 0);
            $idOperacao = (int) ($row['id_operacao'] ?? 0);
            $canal = strtoupper(trim((string) ($row['canal'] ?? 'HIPER_CAIXA')));
            if ($storePdvId <= 0 || $idOperacao <= 0 || $canal === '') {
                continue;
            }

            $composite = $storePdvId . '|' . $canal . '|' . $idOperacao;
            $keys[$composite] = [
                'store_pdv_id' => $storePdvId,
                'canal' => $canal,
                'id_operacao' => $idOperacao,
            ];
        }

        foreach ($keys as $key) {
            DB::table('pdv_vendas')
                ->where('store_pdv_id', $key['store_pdv_id'])
                ->where('canal', $key['canal'])
                ->where('id_operacao', $key['id_operacao'])
                ->update(['last_seen_in_snapshot_at' => $seenAt]);
        }
    }

    private function supportsPdvVendasLastSeenColumn(): bool
    {
        if ($this->hasPdvVendasLastSeenColumn !== null) {
            return $this->hasPdvVendasLastSeenColumn;
        }

        if (!Schema::hasTable('pdv_vendas')) {
            return $this->hasPdvVendasLastSeenColumn = false;
        }

        return $this->hasPdvVendasLastSeenColumn = Schema::hasColumn('pdv_vendas', 'last_seen_in_snapshot_at');
    }

    /**
     * @param array<int, string> $newFlags
     */
    private function appendRiskFlags(PdvSync $sync, array $newFlags): void
    {
        $existing = $sync->risk_flags;
        $existing = is_array($existing) ? $existing : [];

        $sync->risk_flags = array_values(array_unique(array_merge($existing, $newFlags)));
        $sync->save();
    }

    private function storeLockKey(PdvSync $sync): string
    {
        if ($sync->store_id !== null) {
            return 'pdv:store:' . $sync->store_id;
        }

        return 'pdv:store:pdv:' . $sync->store_pdv_id;
    }
}
