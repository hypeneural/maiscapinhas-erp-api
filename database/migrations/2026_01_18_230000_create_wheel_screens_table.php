<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_screens
 * 
 * Representa as TVs/Totens que exibem a roleta nas vitrines das lojas.
 * Cada screen pertence a uma store e possui autenticação via secret token.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_screens', function (Blueprint $table) {
            $table->id();

            // Chave pública única para URLs/admin (ex: screen-tijucas-001)
            $table->string('screen_key', 50)->unique();

            // Vínculo com a loja do ERP
            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            // Nome/apelido da TV (ex: "Vitrine 01")
            $table->string('name', 100);

            // Hash do token de autenticação (gerado via rotate-secret)
            $table->string('secret_token_hash', 255)->nullable();

            // Status da TV
            $table->enum('status', ['active', 'inactive', 'maintenance'])
                ->default('inactive');

            // Informações do dispositivo (user_agent, resolução, etc.)
            $table->json('device_info')->nullable();

            // Última comunicação (heartbeat)
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            // Índices
            $table->index(['store_id', 'status']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_screens');
    }
};
