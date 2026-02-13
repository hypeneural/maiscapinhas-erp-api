<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PdvBootstrapMappingsCommand extends Command
{
    protected $signature = 'pdv:bootstrap-mappings
                            {--dry-run : Simula sem gravar no banco}
                            {--only= : Executa apenas um bloco (cnpjs,users,store-mappings,user-mappings)}';

    protected $description = 'Bootstrap idempotente de CNPJs, usuarios faltantes e mappings PDV (store/user).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        try {
            $only = $this->normalizeOnlyOption($this->option('only'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('PDV bootstrap iniciado.');
        $this->line('dry_run=' . ($dryRun ? 'true' : 'false'));
        $this->line('only=' . ($only ?? 'all'));

        if (!$this->validateSchema()) {
            return self::FAILURE;
        }

        $stats = [
            'stores' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
            'users' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
            'store_mappings' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
            'user_mappings' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
        ];

        DB::beginTransaction();

        try {
            if ($only === null || $only === 'cnpjs') {
                $this->bootstrapStoreCnpjs($dryRun, $stats['stores']);
            }

            $createdUsers = [];
            if ($only === null || $only === 'users') {
                $createdUsers = $this->bootstrapMissingUsers($dryRun, $stats['users']);
            } else {
                $createdUsers = $this->loadMissingUsersByEmail();
            }

            if ($only === null || $only === 'store-mappings') {
                $this->bootstrapStoreMappings($dryRun, $stats['store_mappings']);
            }

            if ($only === null || $only === 'user-mappings') {
                $this->bootstrapUserMappings($dryRun, $stats['user_mappings'], $createdUsers);
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Falha no bootstrap: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Bloco', 'Inserted', 'Updated', 'Skipped'],
            [
                ['stores.cnpj', $stats['stores']['inserted'], $stats['stores']['updated'], $stats['stores']['skipped']],
                ['users', $stats['users']['inserted'], $stats['users']['updated'], $stats['users']['skipped']],
                ['pdv_store_mappings', $stats['store_mappings']['inserted'], $stats['store_mappings']['updated'], $stats['store_mappings']['skipped']],
                ['pdv_user_mappings', $stats['user_mappings']['inserted'], $stats['user_mappings']['updated'], $stats['user_mappings']['skipped']],
            ]
        );

        $this->info($dryRun ? 'Dry-run finalizado (rollback aplicado).' : 'Bootstrap finalizado com sucesso.');

        return self::SUCCESS;
    }

    private function validateSchema(): bool
    {
        $requiredTables = ['stores', 'users', 'pdv_store_mappings', 'pdv_user_mappings'];
        foreach ($requiredTables as $table) {
            if (!Schema::hasTable($table)) {
                $this->error("Tabela obrigatoria ausente: {$table}");
                return false;
            }
        }

        $requiredColumns = [
            'stores' => ['cnpj'],
            'pdv_store_mappings' => ['pdv_store_id', 'store_id', 'alias', 'cnpj', 'active'],
            'pdv_user_mappings' => [
                'store_pdv_id',
                'pdv_user_id',
                'pdv_user_name',
                'pdv_user_login',
                'user_id',
                'is_store_operator',
                'active',
                'source',
                'confidence',
            ],
        ];

        foreach ($requiredColumns as $table => $columns) {
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    $this->error("Coluna obrigatoria ausente: {$table}.{$column}");
                    $this->line('Execute primeiro a migration de normalizacao:');
                    $this->line('php artisan migrate --path=database/migrations/2026_02_13_000350_normalize_pdv_mapping_tables.php --force');

                    return false;
                }
            }
        }

        return true;
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

        $allowed = ['cnpjs', 'users', 'store-mappings', 'user-mappings'];
        if (!in_array($normalized, $allowed, true)) {
            throw new \InvalidArgumentException('Valor invalido para --only. Use: ' . implode(', ', $allowed));
        }

        return $normalized;
    }

    /**
     * @param array{inserted:int,updated:int,skipped:int} $stats
     */
    private function bootstrapStoreCnpjs(bool $dryRun, array &$stats): void
    {
        $cnpjs = [
            1 => '29094289000137',
            2 => '29094289000218',
            3 => '29094289000307',
            4 => '55124985000159',
            5 => '29094289000641',
            6 => '29094289000560',
            7 => '29094289000480',
            8 => '29094289000722',
            9 => '61063019000171',
            10 => '29094289000803',
            11 => '61063019000252',
            12 => '61063019000333',
        ];

        foreach ($cnpjs as $storeId => $cnpj) {
            $existing = DB::table('stores')->where('id', $storeId)->first(['id', 'cnpj']);
            if (!$existing) {
                $stats['skipped']++;
                continue;
            }

            $existingCnpj = $this->normalizeCnpj($existing->cnpj ?? null);
            if ($existingCnpj === $cnpj) {
                $stats['skipped']++;
                continue;
            }

            if (!$dryRun) {
                DB::table('stores')->where('id', $storeId)->update([
                    'cnpj' => $cnpj,
                    'updated_at' => now(),
                ]);
            }

            $stats['updated']++;
        }
    }

    /**
     * @param array{inserted:int,updated:int,skipped:int} $stats
     * @return array<string, int> email => user_id
     */
    private function bootstrapMissingUsers(bool $dryRun, array &$stats): array
    {
        $missingUsers = [
            ['name' => 'Xochil', 'email' => 'pdv.xochil@maiscapinhas.local'],
            ['name' => 'Kelli', 'email' => 'pdv.kelli@maiscapinhas.local'],
            ['name' => 'Rafaeli', 'email' => 'pdv.rafaeli@maiscapinhas.local'],
            ['name' => 'Iagoh', 'email' => 'pdv.iagoh@maiscapinhas.local'],
        ];

        $idsByEmail = [];
        foreach ($missingUsers as $row) {
            $existing = DB::table('users')->where('email', $row['email'])->first(['id', 'name', 'active']);
            if (!$existing) {
                if (!$dryRun) {
                    $id = (int) DB::table('users')->insertGetId([
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'password' => Hash::make(Str::random(48)),
                        'active' => true,
                        'is_super_admin' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $idsByEmail[$row['email']] = $id;
                }
                $stats['inserted']++;
                continue;
            }

            $updates = [];
            if ((string) $existing->name !== $row['name']) {
                $updates['name'] = $row['name'];
            }
            if ((int) ($existing->active ?? 0) !== 1) {
                $updates['active'] = true;
            }

            if ($updates === []) {
                $stats['skipped']++;
                $idsByEmail[$row['email']] = (int) $existing->id;
                continue;
            }

            if (!$dryRun) {
                $updates['updated_at'] = now();
                DB::table('users')->where('id', (int) $existing->id)->update($updates);
            }
            $stats['updated']++;
            $idsByEmail[$row['email']] = (int) $existing->id;
        }

        if ($dryRun) {
            return $this->loadMissingUsersByEmail();
        }

        return $idsByEmail + $this->loadMissingUsersByEmail();
    }

    /**
     * @return array<string, int>
     */
    private function loadMissingUsersByEmail(): array
    {
        return DB::table('users')
            ->whereIn('email', [
                'pdv.xochil@maiscapinhas.local',
                'pdv.kelli@maiscapinhas.local',
                'pdv.rafaeli@maiscapinhas.local',
                'pdv.iagoh@maiscapinhas.local',
            ])
            ->pluck('id', 'email')
            ->mapWithKeys(static fn (mixed $id, mixed $email): array => [(string) $email => (int) $id])
            ->all();
    }

    /**
     * @param array{inserted:int,updated:int,skipped:int} $stats
     */
    private function bootstrapStoreMappings(bool $dryRun, array &$stats): void
    {
        $rows = [
            ['pdv_store_id' => 10, 'alias' => 'Loja 1 - MC Komprão Centro TJ', 'cnpj' => '29094289000137', 'store_id' => 1],
            ['pdv_store_id' => 9, 'alias' => 'Loja 2 - MC Morretes', 'cnpj' => '29094289000218', 'store_id' => 2],
            ['pdv_store_id' => 6, 'alias' => 'Loja 3 -MC Outlet', 'cnpj' => '29094289000307', 'store_id' => 3],
            ['pdv_store_id' => 4, 'alias' => 'Loja 4 - iTuntz', 'cnpj' => '55124985000159', 'store_id' => 4],
            ['pdv_store_id' => 7, 'alias' => 'Loja 5 - MC Komprão BR Tijucas', 'cnpj' => '29094289000641', 'store_id' => 5],
            ['pdv_store_id' => 2, 'alias' => 'Loja 6 - MC Gov Celso Ramos', 'cnpj' => '29094289000560', 'store_id' => 6],
            ['pdv_store_id' => 6, 'alias' => 'Loja 7 - MC Bombinhas', 'cnpj' => '29094289000480', 'store_id' => 7],
            ['pdv_store_id' => 9, 'alias' => 'Loja 8 - MC Mata Atlântica', 'cnpj' => '29094289000722', 'store_id' => 8],
            ['pdv_store_id' => 3, 'alias' => 'Loja 9 - MC Tabuleiro', 'cnpj' => '61063019000171', 'store_id' => 9],
            ['pdv_store_id' => 9, 'alias' => 'Loja 10 - MC P4', 'cnpj' => '29094289000803', 'store_id' => 10],
            ['pdv_store_id' => 2, 'alias' => 'Loja 11 -MC Camboriú Caledônia', 'cnpj' => '61063019000252', 'store_id' => 11],
            ['pdv_store_id' => 13, 'alias' => 'Loja 12 - MC Porto Belo', 'cnpj' => '61063019000333', 'store_id' => 12],
        ];

        foreach ($rows as $row) {
            $this->upsertStoreMapping($row, $dryRun, $stats);
        }
    }

    /**
     * @param array{inserted:int,updated:int,skipped:int} $stats
     * @param array<string, int> $createdUsers
     */
    private function bootstrapUserMappings(bool $dryRun, array &$stats, array $createdUsers): void
    {
        $xochilId = $createdUsers['pdv.xochil@maiscapinhas.local'] ?? null;
        $kelliId = $createdUsers['pdv.kelli@maiscapinhas.local'] ?? null;
        $rafaeliId = $createdUsers['pdv.rafaeli@maiscapinhas.local'] ?? null;
        $iagohId = $createdUsers['pdv.iagoh@maiscapinhas.local'] ?? null;

        $rows = [
            ['pdv_user_id' => 3, 'pdv_user_name' => 'Vitor', 'pdv_user_login' => 'vitor', 'user_id' => 17, 'is_store_operator' => false],
            ['pdv_user_id' => 7, 'pdv_user_name' => 'Ana Paula', 'pdv_user_login' => 'anapaula', 'user_id' => 12, 'is_store_operator' => false],
            ['pdv_user_id' => 20, 'pdv_user_name' => 'Leonardo', 'pdv_user_login' => 'leonardo', 'user_id' => 14, 'is_store_operator' => false],
            ['pdv_user_id' => 26, 'pdv_user_name' => 'Shaiane', 'pdv_user_login' => 'Shaiane', 'user_id' => 26, 'is_store_operator' => false],
            ['pdv_user_id' => 37, 'pdv_user_name' => 'Juliana', 'pdv_user_login' => 'juliana', 'user_id' => 33, 'is_store_operator' => false],
            ['pdv_user_id' => 41, 'pdv_user_name' => 'Bianca Brasil', 'pdv_user_login' => 'biancabrasil', 'user_id' => 19, 'is_store_operator' => false],
            ['pdv_user_id' => 42, 'pdv_user_name' => 'Bianca Moura', 'pdv_user_login' => 'BiaMoura', 'user_id' => 13, 'is_store_operator' => false],
            ['pdv_user_id' => 48, 'pdv_user_name' => 'Laysa', 'pdv_user_login' => 'Laysa', 'user_id' => 18, 'is_store_operator' => false],
            ['pdv_user_id' => 60, 'pdv_user_name' => 'Julia', 'pdv_user_login' => 'julia', 'user_id' => 23, 'is_store_operator' => false],
            ['pdv_user_id' => 63, 'pdv_user_name' => 'Rodrigo', 'pdv_user_login' => 'Rodrigo', 'user_id' => 35, 'is_store_operator' => false],
            ['pdv_user_id' => 66, 'pdv_user_name' => 'Larissa', 'pdv_user_login' => 'Larissa', 'user_id' => 24, 'is_store_operator' => false],
            ['pdv_user_id' => 71, 'pdv_user_name' => 'Liliane', 'pdv_user_login' => 'Liliane', 'user_id' => 39, 'is_store_operator' => false],
            ['pdv_user_id' => 72, 'pdv_user_name' => 'Thayllor', 'pdv_user_login' => 'Thayllor', 'user_id' => 40, 'is_store_operator' => false],
            ['pdv_user_id' => 76, 'pdv_user_name' => 'Isadora', 'pdv_user_login' => 'Isadora', 'user_id' => 21, 'is_store_operator' => false],
            ['pdv_user_id' => 79, 'pdv_user_name' => 'Daren', 'pdv_user_login' => 'daren', 'user_id' => 20, 'is_store_operator' => false],
            ['pdv_user_id' => 83, 'pdv_user_name' => 'Maria Eduarda', 'pdv_user_login' => 'MariaEduarda', 'user_id' => 31, 'is_store_operator' => false],
            ['pdv_user_id' => 84, 'pdv_user_name' => 'Heloisa', 'pdv_user_login' => 'Heloisa', 'user_id' => 27, 'is_store_operator' => false],
            ['pdv_user_id' => 85, 'pdv_user_name' => 'Andrei', 'pdv_user_login' => 'Andrei', 'user_id' => 41, 'is_store_operator' => false],
            ['pdv_user_id' => 88, 'pdv_user_name' => 'Julia Thais', 'pdv_user_login' => 'JuliaThais', 'user_id' => 36, 'is_store_operator' => false],
            ['pdv_user_id' => 89, 'pdv_user_name' => 'Lourdy', 'pdv_user_login' => 'Lourdy', 'user_id' => 38, 'is_store_operator' => false],
            ['pdv_user_id' => 90, 'pdv_user_name' => 'Lais', 'pdv_user_login' => 'Lais', 'user_id' => 37, 'is_store_operator' => false],
            ['pdv_user_id' => 91, 'pdv_user_name' => 'Vitoria', 'pdv_user_login' => 'Vitoria', 'user_id' => 28, 'is_store_operator' => false],
            ['pdv_user_id' => 92, 'pdv_user_name' => 'Jean', 'pdv_user_login' => 'Jean', 'user_id' => 22, 'is_store_operator' => false],
            ['pdv_user_id' => 93, 'pdv_user_name' => 'Dagmar', 'pdv_user_login' => 'Dagmar', 'user_id' => 25, 'is_store_operator' => false],
            ['pdv_user_id' => 94, 'pdv_user_name' => 'Daniel', 'pdv_user_login' => 'Daniel', 'user_id' => 32, 'is_store_operator' => false],
            ['pdv_user_id' => 96, 'pdv_user_name' => 'Julio', 'pdv_user_login' => 'Julio', 'user_id' => 34, 'is_store_operator' => false],
            ['pdv_user_id' => 98, 'pdv_user_name' => 'Xochil', 'pdv_user_login' => 'Xochil', 'user_id' => $xochilId, 'is_store_operator' => false],
            ['pdv_user_id' => 99, 'pdv_user_name' => 'Kelli', 'pdv_user_login' => 'Kelli', 'user_id' => $kelliId, 'is_store_operator' => false],
            ['pdv_user_id' => 100, 'pdv_user_name' => 'Rafaeli', 'pdv_user_login' => 'Rafaeli', 'user_id' => $rafaeliId, 'is_store_operator' => false],
            ['pdv_user_id' => 101, 'pdv_user_name' => 'Iagoh', 'pdv_user_login' => 'Iagoh', 'user_id' => $iagohId, 'is_store_operator' => false],
            ['pdv_user_id' => 2, 'pdv_user_name' => 'Loja 5 - Komprão BR/Tijucas', 'pdv_user_login' => 'tijucas3', 'user_id' => null, 'is_store_operator' => true],
            ['pdv_user_id' => 4, 'pdv_user_name' => 'Ituntz', 'pdv_user_login' => 'ituntz', 'user_id' => null, 'is_store_operator' => true],
            ['pdv_user_id' => 8, 'pdv_user_name' => 'Loja 3 - Outlet', 'pdv_user_login' => 'outlet', 'user_id' => null, 'is_store_operator' => true],
            ['pdv_user_id' => 10, 'pdv_user_name' => 'Loja 01 - Komprao Centro/Tijucas', 'pdv_user_login' => 'tijucas', 'user_id' => null, 'is_store_operator' => true],
            ['pdv_user_id' => 11, 'pdv_user_name' => 'Loja 2 - Morretes/Itapema', 'pdv_user_login' => 'itapema', 'user_id' => null, 'is_store_operator' => true],
            ['pdv_user_id' => 12, 'pdv_user_name' => 'Loja 6 - Governador', 'pdv_user_login' => 'governador', 'user_id' => null, 'is_store_operator' => true],
            ['pdv_user_id' => 44, 'pdv_user_name' => 'Loja 4 - iTuntz', 'pdv_user_login' => 'ituntz1', 'user_id' => null, 'is_store_operator' => true],
            ['pdv_user_id' => 59, 'pdv_user_name' => 'Loja 09 - Tabuleiro/Itapema', 'pdv_user_login' => 'Tabuleiro', 'user_id' => null, 'is_store_operator' => true],
            ['pdv_user_id' => 61, 'pdv_user_name' => 'Loja 7 - Bombinhas', 'pdv_user_login' => 'bombinhas', 'user_id' => null, 'is_store_operator' => true],
            ['pdv_user_id' => 67, 'pdv_user_name' => 'Loja 8 - Mata Atlântica', 'pdv_user_login' => 'mataatlantica', 'user_id' => null, 'is_store_operator' => true],
            ['pdv_user_id' => 80, 'pdv_user_name' => 'Loja 10 - P4', 'pdv_user_login' => 'filial10', 'user_id' => null, 'is_store_operator' => true],
            ['pdv_user_id' => 86, 'pdv_user_name' => 'Loja 11 - Caledônia', 'pdv_user_login' => 'filial11', 'user_id' => null, 'is_store_operator' => true],
            ['pdv_user_id' => 87, 'pdv_user_name' => 'Loja 12 - Porto Belo Komprão', 'pdv_user_login' => 'filial12', 'user_id' => null, 'is_store_operator' => true],
        ];

        foreach ($rows as $row) {
            if (!$row['is_store_operator'] && empty($row['user_id'])) {
                $stats['skipped']++;
                $this->warn('User mapping ignorado (user_id ausente): pdv_user_id=' . $row['pdv_user_id']);
                continue;
            }

            $this->upsertUserMapping($row, $dryRun, $stats);
        }
    }

    /**
     * @param array{
     *   pdv_store_id:int,
     *   alias:string,
     *   cnpj:string,
     *   store_id:int
     * } $row
     * @param array{inserted:int,updated:int,skipped:int} $stats
     */
    private function upsertStoreMapping(array $row, bool $dryRun, array &$stats): void
    {
        $existing = DB::table('pdv_store_mappings')
            ->where('pdv_store_id', $row['pdv_store_id'])
            ->where('alias', $row['alias'])
            ->first(['id', 'store_id', 'cnpj', 'active']);

        if (!$existing) {
            if (!$dryRun) {
                DB::table('pdv_store_mappings')->insert([
                    'pdv_store_id' => $row['pdv_store_id'],
                    'alias' => $row['alias'],
                    'cnpj' => $row['cnpj'],
                    'store_id' => $row['store_id'],
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $stats['inserted']++;
            return;
        }

        $changes = [];
        if ((int) $existing->store_id !== (int) $row['store_id']) {
            $changes['store_id'] = $row['store_id'];
        }
        if ($this->normalizeCnpj($existing->cnpj ?? null) !== $row['cnpj']) {
            $changes['cnpj'] = $row['cnpj'];
        }
        if ((int) ($existing->active ?? 0) !== 1) {
            $changes['active'] = true;
        }

        if ($changes === []) {
            $stats['skipped']++;
            return;
        }

        if (!$dryRun) {
            $changes['updated_at'] = now();
            DB::table('pdv_store_mappings')->where('id', (int) $existing->id)->update($changes);
        }
        $stats['updated']++;
    }

    /**
     * @param array{
     *   pdv_user_id:int,
     *   pdv_user_name:string,
     *   pdv_user_login:string,
     *   user_id:int|null,
     *   is_store_operator:bool
     * } $row
     * @param array{inserted:int,updated:int,skipped:int} $stats
     */
    private function upsertUserMapping(array $row, bool $dryRun, array &$stats): void
    {
        $existing = DB::table('pdv_user_mappings')
            ->where('pdv_user_id', $row['pdv_user_id'])
            ->first(['id', 'user_id', 'pdv_user_name', 'pdv_user_login', 'is_store_operator', 'active', 'source', 'confidence']);

        if (!$existing) {
            if (!$dryRun) {
                DB::table('pdv_user_mappings')->insert([
                    'store_pdv_id' => null,
                    'pdv_user_id' => $row['pdv_user_id'],
                    'pdv_user_name' => $row['pdv_user_name'],
                    'pdv_user_login' => $row['pdv_user_login'],
                    'user_id' => $row['user_id'],
                    'is_store_operator' => $row['is_store_operator'],
                    'active' => true,
                    'source' => 'bootstrap',
                    'confidence' => 100,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $stats['inserted']++;
            return;
        }

        $changes = [];
        if ((int) ($existing->user_id ?? 0) !== (int) ($row['user_id'] ?? 0)) {
            $changes['user_id'] = $row['user_id'];
        }
        if ((string) ($existing->pdv_user_name ?? '') !== (string) $row['pdv_user_name']) {
            $changes['pdv_user_name'] = $row['pdv_user_name'];
        }
        if ((string) ($existing->pdv_user_login ?? '') !== (string) $row['pdv_user_login']) {
            $changes['pdv_user_login'] = $row['pdv_user_login'];
        }
        if ((bool) ($existing->is_store_operator ?? false) !== (bool) $row['is_store_operator']) {
            $changes['is_store_operator'] = $row['is_store_operator'];
        }
        if ((int) ($existing->active ?? 0) !== 1) {
            $changes['active'] = true;
        }
        if ((string) ($existing->source ?? '') !== 'bootstrap') {
            $changes['source'] = 'bootstrap';
        }
        if ((int) ($existing->confidence ?? 0) !== 100) {
            $changes['confidence'] = 100;
        }

        if ($changes === []) {
            $stats['skipped']++;
            return;
        }

        if (!$dryRun) {
            $changes['updated_at'] = now();
            DB::table('pdv_user_mappings')->where('id', (int) $existing->id)->update($changes);
        }
        $stats['updated']++;
    }

    private function normalizeCnpj(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);
        if (!is_string($digits) || $digits === '') {
            return null;
        }

        return $digits;
    }
};
