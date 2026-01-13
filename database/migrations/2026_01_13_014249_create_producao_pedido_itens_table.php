<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('producao_pedido_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producao_pedido_id')->constrained('producao_pedidos')->cascadeOnDelete();
            $table->foreignId('capa_personalizada_id')->constrained('capas_personalizadas')->cascadeOnDelete();

            // Snapshot dos dados no momento do agrupamento
            $table->string('phone_brand')->nullable();
            $table->string('phone_model')->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->text('observation')->nullable();
            $table->string('photo_url')->nullable();

            $table->timestamps();

            // Cada capa só pode estar em 1 pedido de produção
            $table->unique('capa_personalizada_id');

            $table->index('producao_pedido_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producao_pedido_itens');
    }
};
