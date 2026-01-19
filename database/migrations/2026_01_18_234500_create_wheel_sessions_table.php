<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_sessions
 * 
 * Sessão de QR Code ativa por screen.
 * Cada sessão representa uma "rodada" que um jogador pode participar.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_sessions', function (Blueprint $table) {
            $table->id();

            // Chave pública única (para URLs/QR)
            $table->string('session_key', 50)->unique();

            // Screen (TV) que criou a sessão
            $table->foreignId('screen_id')
                ->constrained('wheel_screens')
                ->cascadeOnDelete();

            // Campanha ativa no momento da criação
            $table->foreignId('campaign_id')
                ->constrained('wheel_campaigns')
                ->cascadeOnDelete();

            // Status da sessão
            $table->enum('status', ['waiting', 'active', 'spinning', 'completed', 'expired', 'cancelled'])
                ->default('waiting');

            // QR Code data (URL ou payload)
            $table->string('qr_code_data', 500)->nullable();

            // Quando o QR expira
            $table->timestamp('expires_at');

            // Jogador atual (se houver)
            $table->foreignId('current_player_id')
                ->nullable()
                ->constrained('wheel_sessions') // Será atualizado após criar wheel_players
                ->nullOnDelete();

            // Metadados da sessão
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Índices
            $table->index(['screen_id', 'status']);
            $table->index(['campaign_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_sessions');
    }
};
