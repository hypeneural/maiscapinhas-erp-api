<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_session_players
 * 
 * Pivot entre wheel_sessions e wheel_players.
 * Representa a participação de um jogador em uma sessão específica.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_session_players', function (Blueprint $table) {
            $table->id();

            // Chave pública única (para expor no front)
            $table->string('session_player_key', 50)->unique();

            // Sessão em que está participando
            $table->foreignId('session_id')
                ->constrained('wheel_sessions')
                ->cascadeOnDelete();

            // Jogador (pessoa)
            $table->foreignId('player_id')
                ->constrained('wheel_players')
                ->cascadeOnDelete();

            // Status nesta sessão
            $table->enum('status', [
                'pending',      // Entrou, aguardando verificação
                'verifying',    // Verificação em andamento
                'verified',     // Telefone confirmado, na fila
                'playing',      // É a vez, pode girar
                'spinning',     // Giro em andamento
                'completed',    // Finalizou participação
                'disconnected', // Saiu/timeout
            ])->default('pending');

            // Posição na fila (0 = é a vez)
            $table->unsignedInteger('queue_position')->default(0);

            // Token de acesso para esta sessão
            $table->string('access_token_hash', 255)->nullable();

            // Informações do dispositivo
            $table->json('device_info')->nullable();

            // Aceite dos termos
            $table->string('terms_version', 20)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();

            // IP e User Agent (auditoria)
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Timestamps de participação
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();

            $table->timestamps();

            // Constraints
            $table->unique(['session_id', 'player_id']); // 1 player por sessão

            // Índices
            $table->index(['session_id', 'status']);
            $table->index(['session_id', 'queue_position']);
            $table->index(['player_id', 'created_at']);
        });

        // Atualizar wheel_sessions para referenciar session_player ao invés de player
        Schema::table('wheel_sessions', function (Blueprint $table) {
            // Adicionar nova coluna
            $table->foreignId('current_session_player_id')
                ->nullable()
                ->after('current_player_id')
                ->constrained('wheel_session_players')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wheel_sessions', function (Blueprint $table) {
            $table->dropForeign(['current_session_player_id']);
            $table->dropColumn('current_session_player_id');
        });

        Schema::dropIfExists('wheel_session_players');
    }
};
