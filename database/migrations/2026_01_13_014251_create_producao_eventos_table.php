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
        Schema::create('producao_eventos', function (Blueprint $table) {
            $table->id();

            // Entidade afetada (polimórfico simplificado)
            $table->string('entity_type', 50);  // 'producao_pedido', 'capa_personalizada'
            $table->unsignedBigInteger('entity_id');

            // Ação executada
            $table->string('action', 50);

            // Status antes/depois (se aplicável)
            $table->tinyInteger('from_status')->nullable();
            $table->tinyInteger('to_status')->nullable();

            // Dados extras (valor, observação, código rastreio, etc)
            $table->json('metadata')->nullable();

            // Quem executou
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_type', 20);   // 'admin', 'vendedor', 'fabrica'
            $table->string('actor_name', 100);

            $table->timestamp('created_at');

            // Indexes
            $table->index(['entity_type', 'entity_id', 'created_at']);
            $table->index(['actor_id', 'actor_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producao_eventos');
    }
};
