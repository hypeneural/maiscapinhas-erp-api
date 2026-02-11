<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PdvMapUserCommand extends Command
{
    protected $signature = 'pdv:map-user
                            {store_pdv_id : ID da loja no PDV (id_ponto_venda)}
                            {pdv_user_id : ID do usuario no PDV (id_usuario)}
                            {user_id : ID interno do usuario no ERP}
                            {--source=manual : Origem do mapping (manual/import/auto)}
                            {--confidence=100 : Confianca do mapping (0-100)}
                            {--inactive : Criar/atualizar mapping como inativo}';

    protected $description = 'Create or update PDV user mapping (store_pdv_id + pdv_user_id -> user_id).';

    public function handle(): int
    {
        $storePdvId = (int) $this->argument('store_pdv_id');
        $pdvUserId = (int) $this->argument('pdv_user_id');
        $userId = (int) $this->argument('user_id');
        $source = trim((string) $this->option('source'));
        $source = $source !== '' ? $source : 'manual';
        $confidence = (int) $this->option('confidence');
        $confidence = max(0, min(100, $confidence));
        $active = !$this->option('inactive');

        if ($storePdvId <= 0 || $pdvUserId <= 0 || $userId <= 0) {
            $this->error('store_pdv_id, pdv_user_id e user_id devem ser inteiros positivos.');

            return self::FAILURE;
        }

        $user = DB::table('users')->where('id', $userId)->first(['id', 'name', 'email']);
        if (!$user) {
            $this->error("User ID {$userId} nao existe.");

            return self::FAILURE;
        }

        $storeMapping = DB::table('pdv_store_mappings')
            ->where('pdv_store_id', $storePdvId)
            ->first(['store_id', 'alias', 'active']);

        if (!$storeMapping) {
            $this->warn("Aviso: loja PDV {$storePdvId} ainda sem registro em pdv_store_mappings.");
        } elseif ((int) $storeMapping->active !== 1) {
            $this->warn("Aviso: mapping da loja PDV {$storePdvId} esta inativo.");
        }

        DB::table('pdv_user_mappings')->updateOrInsert(
            [
                'store_pdv_id' => $storePdvId,
                'pdv_user_id' => $pdvUserId,
            ],
            [
                'user_id' => $userId,
                'active' => $active,
                'source' => $source,
                'confidence' => $confidence,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $mapping = DB::table('pdv_user_mappings')
            ->where('store_pdv_id', $storePdvId)
            ->where('pdv_user_id', $pdvUserId)
            ->first(['id', 'store_pdv_id', 'pdv_user_id', 'user_id', 'active', 'source', 'confidence']);

        $this->info('PDV user mapping salvo com sucesso:');
        $this->line(sprintf(
            'id=%d store_pdv_id=%d pdv_user_id=%d user_id=%d active=%d source=%s confidence=%d',
            (int) $mapping->id,
            (int) $mapping->store_pdv_id,
            (int) $mapping->pdv_user_id,
            (int) $mapping->user_id,
            (int) $mapping->active,
            (string) $mapping->source,
            (int) $mapping->confidence
        ));
        $this->line(sprintf('user_name=%s user_email=%s', (string) $user->name, (string) ($user->email ?? 'null')));

        return self::SUCCESS;
    }
}

