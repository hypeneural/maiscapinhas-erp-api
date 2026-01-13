<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('producao_pedidos', function (Blueprint $table) {
            $table->id();

            // Status: 1=carrinho_aberto, 2=encomenda_realizada, 3=pedido_aceito, 4=pedido_despachado, 5=recebido, 6=cancelado
            $table->tinyInteger('status')->default(1);

            // Totais (calculados ao fechar)
            $table->unsignedInteger('total_itens')->default(0);
            $table->unsignedInteger('total_qtd')->default(0);

            // Dados da fábrica
            $table->decimal('factory_total', 10, 2)->nullable();
            $table->text('factory_notes')->nullable();

            // Observação do admin
            $table->text('observation')->nullable();

            // Timestamps por etapa
            $table->timestamp('closed_at')->nullable();      // Carrinho fechado
            $table->timestamp('accepted_at')->nullable();    // Fábrica aceitou
            $table->timestamp('dispatched_at')->nullable();  // Fábrica despachou
            $table->timestamp('received_at')->nullable();    // Admin recebeu

            // Audit
            $table->foreignId('created_by_id')->constrained('users')->cascadeOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['status', 'created_at']);
            $table->index('created_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producao_pedidos');
    }
};
