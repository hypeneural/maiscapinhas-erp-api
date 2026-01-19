<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_prize_rules
 * 
 * Regras avançadas de elegibilidade por prêmio/campanha.
 * Configurável pelo admin.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_prize_rules', function (Blueprint $table) {
            $table->id();

            // Relacionamentos
            $table->foreignId('campaign_id')
                ->constrained('wheel_campaigns')
                ->cascadeOnDelete();

            $table->foreignId('prize_id')
                ->constrained('wheel_prizes')
                ->cascadeOnDelete();

            // ========================================
            // Cooldown por spins
            // ========================================
            // Mínimo de rodadas sem esse prêmio sair
            // Ex: 10 = depois de sair, precisa de 10 spins para poder sair novamente
            $table->unsignedInteger('min_gap_spins')->default(0);

            // ========================================
            // Cooldown por tempo
            // ========================================
            // Segundos mínimos entre saídas
            // Ex: 300 = 5 minutos entre cada vez que sai
            $table->unsignedInteger('cooldown_seconds')->default(0);

            // ========================================
            // Limites por período
            // ========================================
            // Máximo de vezes que pode sair por hora
            $table->unsignedInteger('max_per_hour')->nullable();

            // Máximo de vezes que pode sair por dia
            $table->unsignedInteger('max_per_day')->nullable();

            // ========================================
            // Escopo do cooldown
            // ========================================
            // screen: cooldown separado por TV (cada TV tem seu próprio estado)
            // campaign: cooldown global (todas as TVs compartilham)
            $table->enum('cooldown_scope', ['screen', 'campaign'])->default('campaign');

            // ========================================
            // Pacing (distribuição ao longo da campanha)
            // ========================================
            // Se ativo, prêmio só sai se ainda houver "margem" no ritmo
            $table->boolean('pacing_enabled')->default(false);

            // Buffer acima do ritmo ideal (1.20 = 20% acima)
            // Ex: campanha de 10 dias com 100 unidades = 10/dia ideal
            // Com buffer 1.20, permite até 12/dia antes de frear
            $table->decimal('pacing_buffer', 5, 2)->default(1.20);

            // ========================================
            // Prioridade para fallback
            // ========================================
            // Menor número = maior prioridade quando precisa escolher fallback
            $table->unsignedInteger('priority')->default(100);

            // Ativo/Inativo
            $table->boolean('active')->default(true);

            $table->timestamps();

            // Constraints
            $table->unique(['campaign_id', 'prize_id']);

            // Índices
            $table->index(['campaign_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_prize_rules');
    }
};
