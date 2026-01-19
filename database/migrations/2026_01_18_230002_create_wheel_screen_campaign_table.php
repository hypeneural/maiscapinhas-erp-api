<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_screen_campaign (Pivot)
 * 
 * Vincula quais campanhas estão ativas em quais screens.
 * Uma screen pode ter múltiplas campanhas, mas apenas uma ativa por vez.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_screen_campaign', function (Blueprint $table) {
            $table->id();

            $table->foreignId('screen_id')
                ->constrained('wheel_screens')
                ->cascadeOnDelete();

            $table->foreignId('campaign_id')
                ->constrained('wheel_campaigns')
                ->cascadeOnDelete();

            // Status do vínculo (apenas uma campanha ativa por screen)
            $table->enum('status', ['active', 'inactive'])
                ->default('inactive');

            $table->timestamps();

            // Garante que não há duplicidade screen+campaign
            $table->unique(['screen_id', 'campaign_id']);

            // Índice para buscar campanha ativa por screen
            $table->index(['screen_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_screen_campaign');
    }
};
