<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_events
 * 
 * Log de auditoria para debug e replay.
 * Registra eventos importantes do sistema de roleta.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_events', function (Blueprint $table) {
            $table->id();

            // UUID único do evento
            $table->uuid('event_id')->unique();

            // Tipo do evento (ex: spin_started, prize_won, screen_connected)
            $table->string('type', 50);

            // Referências opcionais
            $table->foreignId('screen_id')
                ->nullable()
                ->constrained('wheel_screens')
                ->nullOnDelete();

            $table->foreignId('campaign_id')
                ->nullable()
                ->constrained('wheel_campaigns')
                ->nullOnDelete();

            // Dados do evento (JSON livre)
            $table->json('payload')->nullable();

            // Apenas created_at para logs (sem updated_at)
            $table->timestamp('created_at')->useCurrent();

            // Índices para consultas
            $table->index(['type', 'created_at']);
            $table->index(['screen_id', 'created_at']);
            $table->index(['campaign_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_events');
    }
};
