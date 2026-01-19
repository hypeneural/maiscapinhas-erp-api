<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_spins
 * 
 * Histórico de giros da roleta com resultado e prêmio.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_spins', function (Blueprint $table) {
            $table->id();

            // Chave pública única
            $table->string('spin_key', 50)->unique();

            // Sessão e jogador
            $table->foreignId('session_id')
                ->constrained('wheel_sessions')
                ->cascadeOnDelete();

            $table->foreignId('player_id')
                ->constrained('wheel_players')
                ->cascadeOnDelete();

            // Campanha (desnormalizado para queries)
            $table->foreignId('campaign_id')
                ->constrained('wheel_campaigns')
                ->cascadeOnDelete();

            // Screen (desnormalizado para queries)
            $table->foreignId('screen_id')
                ->constrained('wheel_screens')
                ->cascadeOnDelete();

            // Status do giro
            $table->enum('status', ['pending', 'processing', 'spinning', 'completed', 'failed', 'cancelled'])
                ->default('pending');

            // Client nonce para idempotência
            $table->string('client_nonce', 100)->nullable();

            // Segmento sorteado
            $table->foreignId('segment_id')
                ->nullable()
                ->constrained('wheel_segments')
                ->nullOnDelete();

            // Prêmio ganho (desnormalizado)
            $table->foreignId('prize_id')
                ->nullable()
                ->constrained('wheel_prizes')
                ->nullOnDelete();

            // Código do prêmio (se aplicável)
            $table->string('prize_code', 50)->nullable();

            // Ângulo final da roleta (graus)
            $table->decimal('final_angle', 8, 2)->nullable();

            // Timestamps do fluxo
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Duração da animação (ms) - telemetria
            $table->unsignedInteger('animation_duration_ms')->nullable();

            // Metadata (fps, latency, etc.)
            $table->json('telemetry')->nullable();

            // Resgate
            $table->boolean('redeemed')->default(false);
            $table->timestamp('redeemed_at')->nullable();
            $table->string('redeemed_by')->nullable(); // user_id ou identificador

            $table->timestamps();

            // Índices
            $table->index(['session_id', 'status']);
            $table->index(['player_id', 'status']);
            $table->index(['campaign_id', 'created_at']);
            $table->index(['screen_id', 'created_at']);
            $table->unique(['session_id', 'client_nonce']); // Idempotência
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_spins');
    }
};
