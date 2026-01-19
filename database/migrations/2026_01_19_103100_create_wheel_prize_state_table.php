<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_prize_state
 * 
 * Estado de execução por prêmio/campanha/escopo.
 * Atualizada automaticamente a cada spin.
 * Evita COUNT em wheel_spins a cada giro (performance).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_prize_state', function (Blueprint $table) {
            $table->id();

            // Relacionamentos
            $table->foreignId('campaign_id')
                ->constrained('wheel_campaigns')
                ->cascadeOnDelete();

            $table->foreignId('prize_id')
                ->constrained('wheel_prizes')
                ->cascadeOnDelete();

            // ========================================
            // Escopo
            // ========================================
            // null = global (scope=campaign)
            // screen_id = por tela (scope=screen)
            $table->unsignedBigInteger('scope_id')->nullable();

            // ========================================
            // Estado de cooldown
            // ========================================
            // Sequência do último spin que esse prêmio saiu
            $table->unsignedBigInteger('last_awarded_spin_seq')->nullable();

            // Timestamp da última vez que saiu
            $table->timestamp('last_awarded_at')->nullable();

            // ========================================
            // Contadores (evita COUNT a cada giro)
            // ========================================
            // Quantas vezes saiu nesta hora
            $table->unsignedInteger('awarded_count_hour')->default(0);

            // Quantas vezes saiu hoje
            $table->unsignedInteger('awarded_count_day')->default(0);

            // Total de vezes que saiu (histórico)
            $table->unsignedInteger('awarded_count_total')->default(0);

            // ========================================
            // Chaves de período (para auto-reset)
            // ========================================
            // Formato: YYYYMMDDHH (ex: 2026011910)
            $table->string('hour_key', 10)->nullable();

            // Formato: YYYYMMDD (ex: 20260119)
            $table->string('day_key', 8)->nullable();

            $table->timestamps();

            // Constraints
            $table->unique(['campaign_id', 'prize_id', 'scope_id'], 'prize_state_unique');

            // Índices
            $table->index(['campaign_id', 'scope_id']);
            $table->index(['campaign_id', 'prize_id']);
        });

        // Adicionar spin_seq na sessions para rastrear sequência
        Schema::table('wheel_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('wheel_sessions', 'spin_seq')) {
                $table->unsignedBigInteger('spin_seq')->default(0)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wheel_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('wheel_sessions', 'spin_seq')) {
                $table->dropColumn('spin_seq');
            }
        });

        Schema::dropIfExists('wheel_prize_state');
    }
};
