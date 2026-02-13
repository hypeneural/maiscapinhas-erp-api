<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PdvUserMapping;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncPdvUserMappings extends Command
{
    protected $signature = 'pdv:sync-user-mappings {--force : Forçar re-análise de mapeamentos existentes}';
    protected $description = 'Sincroniza e normaliza usuários do PDV com usuários do sistema baseando-se em nome e login.';

    public function handle(): int
    {
        $this->info('Iniciando sincronização de mapeamentos de usuários PDV...');

        // 1. Carregar todos os usuários do sistema
        $systemUsers = User::all();
        $this->info("Usuários do sistema encontrados: {$systemUsers->count()}");

        // 2. Verificar existência de tabelas
        if (!\Illuminate\Support\Facades\Schema::hasTable('pdv_usuarios')) {
            $this->error('Tabela pdv_usuarios não encontrada.');
            return 1;
        }

        // 3. Carregar usuários do PDV
        $pdvUsers = DB::table('pdv_usuarios')->where('ativo', true)->get();
        $this->info("Usuários de PDV ativos encontrados: {$pdvUsers->count()}");

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        // 4. Mapear onde cada usuário trabalhou (store_pdv_id)
        // Isso é necessário pois o mapping exige store_pdv_id
        $this->info('Analisando histórico de vendas e turnos para identificar lojas...');

        $userStores = [];

        // De vendas
        $salesSellers = DB::table('pdv_venda_itens')
            ->select('store_pdv_id', 'vendedor_pdv_id')
            ->distinct()
            ->get();

        foreach ($salesSellers as $row) {
            $userStores[$row->vendedor_pdv_id][] = $row->store_pdv_id;
        }

        // De turnos (operador e responsavel)
        $shiftOperators = DB::table('pdv_turnos')
            ->select('store_pdv_id', 'operador_pdv_id')
            ->distinct()
            ->get();

        foreach ($shiftOperators as $row) {
            $userStores[$row->operador_pdv_id][] = $row->store_pdv_id;
        }

        // Consolidar
        foreach ($userStores as $uid => $stores) {
            $userStores[$uid] = array_unique($stores);
        }

        foreach ($pdvUsers as $pdvUser) {
            $pdvId = $pdvUser->id_usuario_hiper;
            $pdvLogin = $pdvUser->login_hiper;
            $pdvNome = $pdvUser->nome_hiper ?? $pdvUser->nome_padronizado;

            // Só podemos mapear se soubermos em qual loja ele atua
            if (!isset($userStores[$pdvId])) {
                // Se o usuário existe no cadastro mas nunca vendeu/operou, não temos store_pdv_id para criar o mapping.
                // Poderíamos criar para todas as lojas? Arriscado. Vamos pular.
                continue;
            }

            // Normalizar
            $normLogin = $pdvLogin ? Str::lower(trim($pdvLogin)) : null;
            $normNome = $pdvNome ? Str::lower(trim($pdvNome)) : null;

            // Tentar Match
            $matchedUser = null;
            $confidence = 0;
            $matchReason = '';

            // 1. Por Login (Email)
            if ($normLogin && filter_var($normLogin, FILTER_VALIDATE_EMAIL)) {
                $matchedUser = $systemUsers->first(fn($u) => Str::lower($u->email) === $normLogin);
                if ($matchedUser) {
                    $confidence = 100;
                    $matchReason = 'exact_email';
                }
            }

            // 2. Por Nome
            if (!$matchedUser && $normNome) {
                // Tenta match exato primeiro
                $matchedUser = $systemUsers->first(fn($u) => Str::lower($u->name) === $normNome);
                if ($matchedUser) {
                    $confidence = 80;
                    $matchReason = 'exact_name';
                }

                // Tenta match parcial seguro (ex: João Silva vs João da Silva) - deixar para v2
            }

            if (!$matchedUser) {
                $stats['skipped']++;
                continue;
            }

            // Mapear usuário (store_pdv_id é requerido, pegamos o primeiro que encontrarmos)
            // Como pdv_user_mappings tem constraint unique por pdv_user_id no banco, só podemos ter uma entrada.
            $storePdvId = $userStores[$pdvId][0] ?? $pdvUser->store_pdv_id ?? 0;

            if (!$storePdvId) {
                continue; // Não podemos criar sem store_pdv_id
            }

            $mapping = PdvUserMapping::firstOrNew([
                'pdv_user_id' => $pdvId,
            ]);

            // Se o mapping já existe e é 'manual', não tocamos, a menos que --force.
            if ($mapping->exists && $mapping->source === 'manual' && !$this->option('force')) {
                continue;
            }

            $originalUserId = $mapping->user_id;

            $mapping->user_id = $matchedUser->id;
            $mapping->store_pdv_id = $storePdvId; // Atualiza para uma loja válida conhecida
            $mapping->pdv_user_name = $pdvNome;
            $mapping->pdv_user_login = $pdvLogin;
            $mapping->active = true;
            $mapping->source = $matchReason;
            $mapping->confidence = $confidence;
            $mapping->updated_at = now();

            if ($mapping->isDirty()) {
                $mapping->save();
                if (!$mapping->exists) { // Era novo
                    $stats['created']++;
                    $this->info("Mapping criado: {$pdvNome} -> {$matchedUser->name} [Loja {$storePdvId}]");
                } else {
                    $stats['updated']++;
                    $this->info("Mapping atualizado: {$pdvNome} -> {$matchedUser->name}");
                }
            }
        }

        $this->table(['Created', 'Updated', 'Skipped (No Match)'], [[$stats['created'], $stats['updated'], $stats['skipped']]]);
        return 0;
    }
}
