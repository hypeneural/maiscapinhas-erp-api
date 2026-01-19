<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_segments
 * 
 * Segmentos (fatias) da roleta por campanha.
 * Cada segmento aponta para um prêmio com peso de probabilidade.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_segments', function (Blueprint $table) {
            $table->id();

            // Campanha dona do segmento
            $table->foreignId('campaign_id')
                ->constrained('wheel_campaigns')
                ->cascadeOnDelete();

            // Chave pública única
            $table->string('segment_key', 50)->unique();

            // Texto exibido no segmento
            $table->string('label', 50);

            // Cor do segmento (hex ou hsl)
            $table->string('color', 20)->default('#3B82F6');

            // Prêmio vinculado
            $table->foreignId('prize_id')
                ->constrained('wheel_prizes')
                ->cascadeOnDelete();

            // Peso da probabilidade (>=1)
            // Quanto maior, mais chance de cair
            $table->unsignedInteger('probability_weight')->default(1);

            // Ordem de exibição na roleta
            $table->unsignedInteger('sort_order')->default(0);

            // Ativo/Inativo
            $table->boolean('active')->default(true);

            $table->timestamps();

            // Índices para performance
            $table->index(['campaign_id', 'active', 'sort_order']);
            $table->index(['campaign_id', 'prize_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_segments');
    }
};
