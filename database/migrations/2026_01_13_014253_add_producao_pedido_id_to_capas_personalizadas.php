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
        Schema::table('capas_personalizadas', function (Blueprint $table) {
            // Adicionar referência ao pedido de produção
            $table->foreignId('producao_pedido_id')
                ->nullable()
                ->after('sended_to_production_at')
                ->constrained('producao_pedidos')
                ->nullOnDelete();

            $table->index('producao_pedido_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capas_personalizadas', function (Blueprint $table) {
            $table->dropForeign(['producao_pedido_id']);
            $table->dropIndex(['producao_pedido_id']);
            $table->dropColumn('producao_pedido_id');
        });
    }
};
