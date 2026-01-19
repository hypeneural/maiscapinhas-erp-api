<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_prizes
 * 
 * Catálogo de prêmios disponíveis para a roleta.
 * Os prêmios são reutilizáveis entre campanhas via segments.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_prizes', function (Blueprint $table) {
            $table->id();

            // Chave pública única
            $table->string('prize_key', 50)->unique();

            // Nome do prêmio
            $table->string('name', 100);

            // Tipo do prêmio
            $table->enum('type', ['product', 'coupon', 'nothing', 'try_again'])
                ->default('product');

            // Ícone (emoji ou slug)
            $table->string('icon', 50)->nullable();

            // Descrição completa
            $table->text('description')->nullable();

            // Instruções de resgate (como retirar o prêmio)
            $table->text('redeem_instructions')->nullable();

            // Prefixo para geração de códigos (ex: MC-)
            $table->string('code_prefix', 20)->nullable();

            // Ativo/Inativo
            $table->boolean('active')->default(true);

            $table->timestamps();

            // Índices
            $table->index(['type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_prizes');
    }
};
