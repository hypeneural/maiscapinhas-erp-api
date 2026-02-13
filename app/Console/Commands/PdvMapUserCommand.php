<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PdvMapUserCommand extends Command
{
    protected $signature = 'pdv:map-user
                            {pdv_user_id : ID do usuario no PDV (id_usuario)}
                            {user_id? : ID interno do usuario no ERP}
                            {--store-pdv-id= : Store PDV legado (opcional, apenas historico)}
                            {--name= : Nome do usuario no PDV}
                            {--login= : Login do usuario no PDV}
                            {--operator : Marcar como operador generico de loja (user_id = null)}
                            {--source=manual : Origem do mapping (manual/import/auto)}
                            {--confidence=100 : Confianca do mapping (0-100)}
                            {--inactive : Criar/atualizar mapping como inativo}';

    protected $description = 'Create or update PDV user mapping (global by pdv_user_id).';

    public function handle(): int
    {
        $pdvUserId = (int) $this->argument('pdv_user_id');
        $rawUserId = $this->argument('user_id');
        $userId = $rawUserId !== null ? (int) $rawUserId : null;
        $isOperator = (bool) $this->option('operator');
        $storePdvId = $this->option('store-pdv-id') !== null
            ? (int) $this->option('store-pdv-id')
            : null;
        $pdvUserName = $this->normalizeText($this->option('name'));
        $pdvUserLogin = $this->normalizeText($this->option('login'));
        $source = trim((string) $this->option('source'));
        $source = $source !== '' ? $source : 'manual';
        $confidence = (int) $this->option('confidence');
        $confidence = max(0, min(100, $confidence));
        $active = !$this->option('inactive');

        if ($pdvUserId <= 0) {
            $this->error('pdv_user_id deve ser inteiro positivo.');

            return self::FAILURE;
        }

        if ($storePdvId !== null && $storePdvId <= 0) {
            $this->error('--store-pdv-id deve ser inteiro positivo quando informado.');

            return self::FAILURE;
        }

        $user = null;
        if (!$isOperator) {
            if ($userId === null || $userId <= 0) {
                $this->error('user_id e obrigatorio quando --operator nao for informado.');

                return self::FAILURE;
            }

            $user = DB::table('users')->where('id', $userId)->first(['id', 'name', 'email']);
            if (!$user) {
                $this->error("User ID {$userId} nao existe.");

                return self::FAILURE;
            }
        } else {
            $userId = null;
        }

        DB::table('pdv_user_mappings')->updateOrInsert(
            [
                'pdv_user_id' => $pdvUserId,
            ],
            [
                'store_pdv_id' => $storePdvId,
                'pdv_user_name' => $pdvUserName,
                'pdv_user_login' => $pdvUserLogin,
                'user_id' => $userId,
                'is_store_operator' => $isOperator,
                'active' => $active,
                'source' => $source,
                'confidence' => $confidence,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $mapping = DB::table('pdv_user_mappings')
            ->where('pdv_user_id', $pdvUserId)
            ->first([
                'id',
                'store_pdv_id',
                'pdv_user_id',
                'pdv_user_name',
                'pdv_user_login',
                'user_id',
                'is_store_operator',
                'active',
                'source',
                'confidence',
            ]);

        $this->info('PDV user mapping salvo com sucesso:');
        $this->line(sprintf(
            'id=%d pdv_user_id=%d store_pdv_id=%s user_id=%s operator=%d active=%d source=%s confidence=%d',
            (int) $mapping->id,
            (int) $mapping->pdv_user_id,
            $mapping->store_pdv_id !== null ? (string) ((int) $mapping->store_pdv_id) : 'null',
            $mapping->user_id !== null ? (string) ((int) $mapping->user_id) : 'null',
            (int) ((bool) ($mapping->is_store_operator ?? false)),
            (int) $mapping->active,
            (string) $mapping->source,
            (int) $mapping->confidence
        ));
        $this->line(sprintf(
            'pdv_user_name=%s pdv_user_login=%s',
            (string) ($mapping->pdv_user_name ?? 'null'),
            (string) ($mapping->pdv_user_login ?? 'null')
        ));

        if ($user) {
            $this->line(sprintf(
                'user_name=%s user_email=%s',
                (string) $user->name,
                (string) ($user->email ?? 'null')
            ));
        }

        return self::SUCCESS;
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }
}
