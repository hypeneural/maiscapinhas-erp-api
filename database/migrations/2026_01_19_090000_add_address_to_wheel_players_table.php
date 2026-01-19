<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refatoração: wheel_players
 * 
 * Adiciona campos de endereço (ViaCEP) e prepara para desacoplar de session.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('wheel_players', function (Blueprint $table) {
            // Renomear phone para whatsapp_e164
            $table->renameColumn('phone', 'whatsapp_e164');

            // Adicionar full_name (renomear se existir ou criar)
            if (!Schema::hasColumn('wheel_players', 'full_name')) {
                $table->string('full_name', 100)->nullable()->after('player_key');
            }

            // WhatsApp Linked ID (Evolution API)
            $table->string('whatsapp_lid', 50)->nullable()->unique()->after('whatsapp_e164');

            // Renomear phone_verified_at para whatsapp_confirmed_at
            $table->renameColumn('phone_verified_at', 'whatsapp_confirmed_at');
        });

        // Segunda etapa: adicionar campos de endereço
        Schema::table('wheel_players', function (Blueprint $table) {
            // Endereço (ViaCEP)
            $table->string('cep', 9)->nullable()->after('whatsapp_confirmed_at');
            $table->string('street', 200)->nullable()->after('cep');
            $table->string('number', 20)->nullable()->after('street');
            $table->string('complement', 100)->nullable()->after('number');
            $table->string('neighborhood', 100)->nullable()->after('complement');
            $table->string('city', 100)->nullable()->after('neighborhood');
            $table->string('state', 2)->nullable()->after('city');
            $table->string('ibge', 10)->nullable()->after('state');
            $table->string('ddd', 3)->nullable()->after('ibge');
            $table->string('siafi', 10)->nullable()->after('ddd');
            $table->json('viacep_raw')->nullable()->after('siafi');
            $table->timestamp('viacep_synced_at')->nullable()->after('viacep_raw');

            // Controle de atividade
            $table->timestamp('last_seen_at')->nullable()->after('viacep_synced_at');

            // Índice para busca por CEP/cidade
            $table->index('cep');
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::table('wheel_players', function (Blueprint $table) {
            // Remover campos de endereço
            $table->dropIndex(['cep']);
            $table->dropIndex(['city']);

            $table->dropColumn([
                'whatsapp_lid',
                'cep',
                'street',
                'number',
                'complement',
                'neighborhood',
                'city',
                'state',
                'ibge',
                'ddd',
                'siafi',
                'viacep_raw',
                'viacep_synced_at',
                'last_seen_at',
            ]);

            // Reverter renomeações
            $table->renameColumn('whatsapp_e164', 'phone');
            $table->renameColumn('whatsapp_confirmed_at', 'phone_verified_at');
        });
    }
};
