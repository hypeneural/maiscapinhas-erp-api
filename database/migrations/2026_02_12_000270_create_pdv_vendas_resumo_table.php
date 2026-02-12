<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_vendas_resumo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_pdv_id');
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('canal', 20)->default('HIPER_CAIXA');
            $table->unsignedBigInteger('id_operacao');
            $table->dateTime('data_hora_inicio')->nullable();
            $table->dateTime('data_hora_termino')->nullable();
            $table->unsignedInteger('duracao_segundos')->nullable();
            $table->string('id_turno', 64)->nullable();
            $table->unsignedSmallInteger('turno_seq')->nullable();
            $table->unsignedBigInteger('vendedor_pdv_id')->nullable();
            $table->string('vendedor_nome', 200)->nullable();
            $table->unsignedInteger('qtd_itens')->default(0);
            $table->decimal('total_itens', 14, 2)->default(0);
            $table->string('last_sync_id', 128)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['store_pdv_id', 'canal', 'id_operacao'], 'pdv_vendas_resumo_unique_key');
            $table->index(['store_pdv_id', 'data_hora_inicio'], 'pdv_vendas_resumo_idx_store_data_inicio');
            $table->index(['store_pdv_id', 'vendedor_pdv_id'], 'pdv_vendas_resumo_idx_store_vendedor');
            $table->index('canal', 'pdv_vendas_resumo_idx_canal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_vendas_resumo');
    }
};
