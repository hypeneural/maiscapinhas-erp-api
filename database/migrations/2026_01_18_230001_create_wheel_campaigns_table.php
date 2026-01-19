<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_campaigns
 * 
 * Campanhas da roleta com configurações de duração, termos e limites.
 * Cada campanha pode ser vinculada a múltiplas screens.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_campaigns', function (Blueprint $table) {
            $table->id();

            // Chave pública única (ex: camp_2026_verao)
            $table->string('campaign_key', 50)->unique();

            // Nome da campanha
            $table->string('name', 150);

            // Status do ciclo de vida
            $table->enum('status', ['draft', 'active', 'paused', 'ended'])
                ->default('draft');

            // Período de validade (nullable = sem limite)
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Versão dos termos de uso (ex: 2026-01)
            $table->string('terms_version', 20)->nullable();

            // Configurações JSON da roleta
            // {
            //   "qr_ttl_seconds": 120,
            //   "spin_duration_ms": 8000,
            //   "min_rotations": 5,
            //   "max_rotations": 8,
            //   "max_queue_size": 10,
            //   "per_phone_limit": "1_per_campaign"
            // }
            $table->json('settings')->nullable();

            $table->timestamps();

            // Índices
            $table->index('status');
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_campaigns');
    }
};
