<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_inventory
 * 
 * Controle de limite/estoque de prêmios por campanha.
 * Permite definir limites totais e diários.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_inventory', function (Blueprint $table) {
            $table->id();

            // Campanha
            $table->foreignId('campaign_id')
                ->constrained('wheel_campaigns')
                ->cascadeOnDelete();

            // Prêmio
            $table->foreignId('prize_id')
                ->constrained('wheel_prizes')
                ->cascadeOnDelete();

            // Limite total (null = ilimitado)
            $table->unsignedInteger('total_limit')->nullable();

            // Quantidade restante do total
            $table->unsignedInteger('remaining')->nullable();

            // Limite diário (null = ilimitado)
            $table->unsignedInteger('daily_limit')->nullable();

            // Quantidade restante do dia
            $table->unsignedInteger('daily_remaining')->nullable();

            // Último reset do limite diário
            $table->timestamp('reset_daily_at')->nullable();

            $table->timestamps();

            // Um prêmio por campanha
            $table->unique(['campaign_id', 'prize_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_inventory');
    }
};
