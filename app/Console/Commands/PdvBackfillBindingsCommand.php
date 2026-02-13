<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Pdv\PdvStoreResolver;
use App\Support\Pdv\PdvUserResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PdvBackfillBindingsCommand extends Command
{
    protected $signature = 'pdv:backfill-bindings
                            {--dry-run : Simula sem gravar no banco}
                            {--since= : Data inicial (YYYY-MM-DD) para limitar o backfill}
                            {--only= : Executa apenas um bloco (stores,vendedores,operadores)}
                            {--limit= : Limite de linhas por bloco}';

    protected $description = 'Backfill historico de bindings (store_id / vendedor_user_id / operador_user_id) usando CNPJ + login.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->parseLimit($this->option('limit'));

        try {
            $only = $this->normalizeOnlyOption($this->option('only'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $since = null;
        if (is_string($this->option('since')) && trim((string) $this->option('since')) !== '') {
            try {
                $since = CarbonImmutable::parse((string) $this->option('since'))->startOfDay();
            } catch (\Throwable) {
                $this->error('Valor invalido para --since. Use YYYY-MM-DD.');
                return self::FAILURE;
            }
        }

        $this->info('PDV backfill iniciado.');
        $this->line('dry_run=' . ($dryRun ? 'true' : 'false'));
        $this->line('only=' . ($only ?? 'all'));
        $this->line('since=' . ($since?->toDateString() ?? 'null'));
        $this->line('limit=' . ($limit !== null ? (string) $limit : 'null'));

        if (!$this->validateSchema($only)) {
            return self::FAILURE;
        }

        $stats = [
            'stores' => [
                'resolved' => 0,
                'missing' => 0,
                'ambiguous' => 0,
                'syncs_updated' => 0,
                'vendas_updated' => 0,
                'itens_updated' => 0,
                'pagamentos_updated' => 0,
                'turnos_updated' => 0,
                'turno_pagamentos_updated' => 0,
                'vendas_resumo_updated' => 0,
            ],
            'vendedores' => [
                'would_update' => 0,
                'updated' => 0,
                'missing' => 0,
                'operator' => 0,
                'skipped' => 0,
            ],
            'operadores' => [
                'would_update' => 0,
                'updated' => 0,
                'missing' => 0,
                'operator' => 0,
                'skipped' => 0,
            ],
        ];

        try {
            if ($only === null || $only === 'stores') {
                $this->backfillStoreBindings($dryRun, $since, $limit, $stats['stores']);
            }

            if ($only === null || $only === 'vendedores') {
                $this->backfillVendedorUserIds($dryRun, $since, $limit, $stats['vendedores']);
            }

            if ($only === null || $only === 'operadores') {
                $this->backfillOperadorUserIds($dryRun, $since, $limit, $stats['operadores']);
            }
        } catch (\Throwable $e) {
            $this->error('Falha no backfill: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backfill finalizado.');

        $this->table(
            ['Bloco', 'Campo', 'Valor'],
            [
                ['stores', 'resolved', $stats['stores']['resolved']],
                ['stores', 'missing', $stats['stores']['missing']],
                ['stores', 'ambiguous', $stats['stores']['ambiguous']],
                ['stores', 'syncs_updated', $stats['stores']['syncs_updated']],
                ['stores', 'vendas_updated', $stats['stores']['vendas_updated']],
                ['stores', 'itens_updated', $stats['stores']['itens_updated']],
                ['stores', 'pagamentos_updated', $stats['stores']['pagamentos_updated']],
                ['stores', 'turnos_updated', $stats['stores']['turnos_updated']],
                ['stores', 'turno_pagamentos_updated', $stats['stores']['turno_pagamentos_updated']],
                ['stores', 'vendas_resumo_updated', $stats['stores']['vendas_resumo_updated']],
                ['vendedores', 'would_update', $stats['vendedores']['would_update']],
                ['vendedores', 'updated', $stats['vendedores']['updated']],
                ['vendedores', 'missing', $stats['vendedores']['missing']],
                ['vendedores', 'operator', $stats['vendedores']['operator']],
                ['vendedores', 'skipped', $stats['vendedores']['skipped']],
                ['operadores', 'would_update', $stats['operadores']['would_update']],
                ['operadores', 'updated', $stats['operadores']['updated']],
                ['operadores', 'missing', $stats['operadores']['missing']],
                ['operadores', 'operator', $stats['operadores']['operator']],
                ['operadores', 'skipped', $stats['operadores']['skipped']],
            ]
        );

        return self::SUCCESS;
    }

    private function normalizeOnlyOption(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim(strtolower($value));
        if ($normalized === '') {
            return null;
        }

        $allowed = ['stores', 'vendedores', 'operadores'];
        if (!in_array($normalized, $allowed, true)) {
            throw new \InvalidArgumentException('Valor invalido para --only. Use: ' . implode(', ', $allowed));
        }

        return $normalized;
    }

    private function parseLimit(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function validateSchema(?string $only): bool
    {
        if ($only === null || $only === 'stores') {
            $requiredTables = ['pdv_syncs', 'pdv_sync_payloads', 'pdv_store_mappings', 'pdv_vendas'];
            foreach ($requiredTables as $table) {
                if (!Schema::hasTable($table)) {
                    $this->error("Tabela obrigatoria ausente: {$table}");
                    return false;
                }
            }
        }

        if ($only === null || $only === 'vendedores') {
            $requiredTables = ['pdv_venda_itens', 'pdv_user_mappings'];
            foreach ($requiredTables as $table) {
                if (!Schema::hasTable($table)) {
                    $this->error("Tabela obrigatoria ausente: {$table}");
                    return false;
                }
            }

            if (!Schema::hasColumn('pdv_venda_itens', 'vendedor_login')) {
                $this->warn('Coluna pdv_venda_itens.vendedor_login nao existe. Pulando backfill de vendedores.');
            }
            if (!Schema::hasColumn('pdv_venda_itens', 'vendedor_user_id')) {
                $this->warn('Coluna pdv_venda_itens.vendedor_user_id nao existe. Pulando backfill de vendedores.');
            }
        }

        if ($only === null || $only === 'operadores') {
            if (!Schema::hasTable('pdv_turnos') || !Schema::hasTable('pdv_user_mappings')) {
                $this->error('Tabela obrigatoria ausente: pdv_turnos ou pdv_user_mappings');
                return false;
            }

            if (!Schema::hasColumn('pdv_turnos', 'operador_login') || !Schema::hasColumn('pdv_turnos', 'operador_user_id')) {
                $this->warn('Colunas de operador (operador_login/operador_user_id) ausentes em pdv_turnos. Pulando backfill de operadores.');
            }
        }

        return true;
    }

    /**
     * @param array{
     *   resolved:int,missing:int,ambiguous:int,
     *   syncs_updated:int,vendas_updated:int,itens_updated:int,pagamentos_updated:int,
     *   turnos_updated:int,turno_pagamentos_updated:int,vendas_resumo_updated:int
     * } $stats
     */
    private function backfillStoreBindings(bool $dryRun, ?CarbonImmutable $since, ?int $limit, array &$stats): void
    {
        $resolver = app(PdvStoreResolver::class);

        $query = DB::table('pdv_syncs as s')
            ->join('pdv_sync_payloads as p', 'p.pdv_sync_id', '=', 's.id')
            ->whereNull('s.store_id')
            ->orderBy('s.id');

        if ($since !== null) {
            $query->where('s.received_at', '>=', $since->toDateTimeString());
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $rows = $query->get([
            's.id',
            's.sync_id',
            's.store_pdv_id',
            's.store_alias',
            'p.payload',
        ]);

        foreach ($rows as $row) {
            $payloadRaw = (string) ($row->payload ?? '');
            $payload = json_decode($payloadRaw, true);
            if (!is_array($payload)) {
                continue;
            }

            $resolved = $resolver->resolveFromPayload($payload);
            if (($resolved['status'] ?? 'missing') === 'ambiguous') {
                $stats['ambiguous']++;
                continue;
            }
            if (($resolved['status'] ?? 'missing') !== 'resolved' || empty($resolved['store_id'])) {
                $stats['missing']++;
                continue;
            }

            $stats['resolved']++;

            $syncId = (string) ($row->sync_id ?? '');
            if ($syncId === '') {
                continue;
            }

            $storeId = (int) $resolved['store_id'];
            if ($storeId <= 0) {
                continue;
            }

            if ($dryRun) {
                $stats['syncs_updated']++;
                continue;
            }

            DB::beginTransaction();
            try {
                $stats['syncs_updated'] += (int) DB::table('pdv_syncs')
                    ->where('id', (int) $row->id)
                    ->whereNull('store_id')
                    ->update([
                        'store_id' => $storeId,
                        'updated_at' => now(),
                    ]);

                $stats['vendas_updated'] += (int) DB::table('pdv_vendas')
                    ->where('sync_id', $syncId)
                    ->whereNull('store_id')
                    ->update([
                        'store_id' => $storeId,
                        'updated_at' => now(),
                    ]);

                $stats['itens_updated'] += (int) DB::table('pdv_venda_itens as vi')
                    ->whereNull('vi.store_id')
                    ->whereExists(function ($sub) use ($syncId): void {
                        $sub->selectRaw('1')
                            ->from('pdv_vendas as v')
                            ->whereColumn('v.store_pdv_id', 'vi.store_pdv_id')
                            ->whereColumn('v.canal', 'vi.canal')
                            ->whereColumn('v.id_operacao', 'vi.id_operacao')
                            ->where('v.sync_id', $syncId);
                    })
                    ->update([
                        'store_id' => $storeId,
                        'updated_at' => now(),
                    ]);

                $stats['pagamentos_updated'] += (int) DB::table('pdv_venda_pagamentos as vp')
                    ->whereNull('vp.store_id')
                    ->whereExists(function ($sub) use ($syncId): void {
                        $sub->selectRaw('1')
                            ->from('pdv_vendas as v')
                            ->whereColumn('v.store_pdv_id', 'vp.store_pdv_id')
                            ->whereColumn('v.canal', 'vp.canal')
                            ->whereColumn('v.id_operacao', 'vp.id_operacao')
                            ->where('v.sync_id', $syncId);
                    })
                    ->update([
                        'store_id' => $storeId,
                        'updated_at' => now(),
                    ]);

                if (Schema::hasTable('pdv_turnos') && Schema::hasColumn('pdv_turnos', 'last_sync_id')) {
                    $stats['turnos_updated'] += (int) DB::table('pdv_turnos')
                        ->whereNull('store_id')
                        ->where('last_sync_id', $syncId)
                        ->update([
                            'store_id' => $storeId,
                            'updated_at' => now(),
                        ]);
                }

                if (Schema::hasTable('pdv_turno_pagamentos') && Schema::hasTable('pdv_turnos') && Schema::hasColumn('pdv_turnos', 'last_sync_id')) {
                    $stats['turno_pagamentos_updated'] += (int) DB::table('pdv_turno_pagamentos as tp')
                        ->whereNull('tp.store_id')
                        ->whereExists(function ($sub) use ($syncId): void {
                            $sub->selectRaw('1')
                                ->from('pdv_turnos as t')
                                ->whereColumn('t.store_pdv_id', 'tp.store_pdv_id')
                                ->whereColumn('t.id_turno', 'tp.id_turno')
                                ->where('t.last_sync_id', $syncId);
                        })
                        ->update([
                            'store_id' => $storeId,
                            'updated_at' => now(),
                        ]);
                }

                if (Schema::hasTable('pdv_vendas_resumo') && Schema::hasColumn('pdv_vendas_resumo', 'last_sync_id')) {
                    $stats['vendas_resumo_updated'] += (int) DB::table('pdv_vendas_resumo')
                        ->whereNull('store_id')
                        ->where('last_sync_id', $syncId)
                        ->update([
                            'store_id' => $storeId,
                            'updated_at' => now(),
                        ]);
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }
    }

    /**
     * @param array{would_update:int,updated:int,missing:int,operator:int,skipped:int} $stats
     */
    private function backfillVendedorUserIds(bool $dryRun, ?CarbonImmutable $since, ?int $limit, array &$stats): void
    {
        if (!Schema::hasColumn('pdv_venda_itens', 'vendedor_login') || !Schema::hasColumn('pdv_venda_itens', 'vendedor_user_id')) {
            return;
        }

        $userResolver = app(PdvUserResolver::class);
        $mappings = $userResolver->loadActiveMappings();

        $processed = 0;
        DB::table('pdv_venda_itens as vi')
            ->whereNull('vi.vendedor_user_id')
            ->whereNotNull('vi.vendedor_login')
            ->where('vi.vendedor_login', '!=', '')
            ->when($since !== null, function ($query) use ($since): void {
                $query->whereExists(function ($sub) use ($since): void {
                    $sub->selectRaw('1')
                        ->from('pdv_vendas as v')
                        ->whereColumn('v.store_pdv_id', 'vi.store_pdv_id')
                        ->whereColumn('v.canal', 'vi.canal')
                        ->whereColumn('v.id_operacao', 'vi.id_operacao')
                        ->where('v.data_hora', '>=', $since->toDateTimeString());
                });
            })
            ->chunkById(500, function ($rows) use ($dryRun, $mappings, $userResolver, &$stats, &$processed, $limit): bool {
                foreach ($rows as $row) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }

                    $processed++;

                    $login = is_string($row->vendedor_login ?? null) ? trim((string) $row->vendedor_login) : '';
                    if ($login === '') {
                        $stats['skipped']++;
                        continue;
                    }

                    $resolution = $userResolver->resolve(null, $login, $mappings);
                    if (($resolution['status'] ?? 'missing') === 'operator') {
                        $stats['operator']++;
                        continue;
                    }
                    if (($resolution['status'] ?? 'missing') !== 'resolved' || empty($resolution['user_id'])) {
                        $stats['missing']++;
                        continue;
                    }

                    $userId = (int) $resolution['user_id'];
                    if ($userId <= 0) {
                        $stats['missing']++;
                        continue;
                    }

                    if ($dryRun) {
                        $stats['would_update']++;
                        continue;
                    }

                    $affected = (int) DB::table('pdv_venda_itens')
                        ->where('id', (int) $row->id)
                        ->whereNull('vendedor_user_id')
                        ->update([
                            'vendedor_user_id' => $userId,
                            'updated_at' => now(),
                        ]);

                    if ($affected > 0) {
                        $stats['updated']++;
                    }
                }

                return true;
            }, 'id');
    }

    /**
     * @param array{would_update:int,updated:int,missing:int,operator:int,skipped:int} $stats
     */
    private function backfillOperadorUserIds(bool $dryRun, ?CarbonImmutable $since, ?int $limit, array &$stats): void
    {
        if (!Schema::hasColumn('pdv_turnos', 'operador_login') || !Schema::hasColumn('pdv_turnos', 'operador_user_id')) {
            return;
        }

        $userResolver = app(PdvUserResolver::class);
        $mappings = $userResolver->loadActiveMappings();

        $processed = 0;
        DB::table('pdv_turnos as t')
            ->whereNull('t.operador_user_id')
            ->whereNotNull('t.operador_login')
            ->where('t.operador_login', '!=', '')
            ->when($since !== null, fn ($query) => $query->where('t.data_hora_inicio', '>=', $since->toDateTimeString()))
            ->chunkById(500, function ($rows) use ($dryRun, $mappings, $userResolver, &$stats, &$processed, $limit): bool {
                foreach ($rows as $row) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }

                    $processed++;

                    $login = is_string($row->operador_login ?? null) ? trim((string) $row->operador_login) : '';
                    if ($login === '') {
                        $stats['skipped']++;
                        continue;
                    }

                    $resolution = $userResolver->resolve(null, $login, $mappings);
                    if (($resolution['status'] ?? 'missing') === 'operator') {
                        $stats['operator']++;
                        continue;
                    }
                    if (($resolution['status'] ?? 'missing') !== 'resolved' || empty($resolution['user_id'])) {
                        $stats['missing']++;
                        continue;
                    }

                    $userId = (int) $resolution['user_id'];
                    if ($userId <= 0) {
                        $stats['missing']++;
                        continue;
                    }

                    if ($dryRun) {
                        $stats['would_update']++;
                        continue;
                    }

                    $affected = (int) DB::table('pdv_turnos')
                        ->where('id', (int) $row->id)
                        ->whereNull('operador_user_id')
                        ->update([
                            'operador_user_id' => $userId,
                            'updated_at' => now(),
                        ]);

                    if ($affected > 0) {
                        $stats['updated']++;
                    }
                }

                return true;
            }, 'id');
    }
}
