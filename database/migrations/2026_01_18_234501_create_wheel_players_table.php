<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_players
 * 
 * Jogadores que participam das sessões da roleta.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_players', function (Blueprint $table) {
            $table->id();

            // Chave pública única
            $table->string('player_key', 50)->unique();

            // Sessão que o jogador está participando
            $table->foreignId('session_id')
                ->constrained('wheel_sessions')
                ->cascadeOnDelete();

            // Telefone (formato E.164, ex: +5548999999999)
            $table->string('phone', 20);

            // Telefone mascarado para exibição (ex: +55 48 *****-9999)
            $table->string('phone_masked', 30);

            // Hash do telefone (para busca rápida sem expor)
            $table->string('phone_hash', 64)->index();

            // Status do jogador
            $table->enum('status', ['pending', 'verifying', 'verified', 'spinning', 'won', 'lost', 'left', 'timeout'])
                ->default('pending');

            // Posição na fila (1 = primeiro)
            $table->unsignedInteger('queue_position')->default(0);

            // Token de acesso do player (JWT ou random)
            $table->string('access_token_hash', 255)->nullable();

            // Verificação de telefone
            $table->boolean('phone_verified')->default(false);
            $table->timestamp('phone_verified_at')->nullable();

            // IP e User Agent (para auditoria/anti-fraude)
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Aceite dos termos
            $table->string('terms_version', 20)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();

            $table->timestamps();

            // Índices
            $table->index(['session_id', 'status']);
            $table->index(['session_id', 'queue_position']);
        });

        // Atualizar referência na wheel_sessions
        Schema::table('wheel_sessions', function (Blueprint $table) {
            // Remover a constraint incorreta e recriar
            $table->dropForeign(['current_player_id']);
            $table->foreign('current_player_id')
                ->references('id')
                ->on('wheel_players')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wheel_sessions', function (Blueprint $table) {
            $table->dropForeign(['current_player_id']);
        });

        Schema::dropIfExists('wheel_players');
    }
};
