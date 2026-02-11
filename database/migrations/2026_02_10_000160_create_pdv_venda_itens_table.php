<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_venda_itens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_pdv_id');
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->unsignedBigInteger('id_operacao');
            $table->unsignedInteger('line_no');
            $table->unsignedBigInteger('id_produto')->nullable();
            $table->string('codigo_barras', 80)->nullable();
            $table->string('nome_produto', 300)->nullable();
            $table->decimal('qtd', 14, 3)->default(1);
            $table->decimal('preco_unit', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('desconto', 14, 2)->default(0);
            $table->unsignedBigInteger('vendedor_pdv_id')->nullable();
            $table->string('vendedor_nome', 200)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['store_pdv_id', 'id_operacao', 'line_no'], 'pdv_venda_itens_unique_line');
            $table->index(['store_pdv_id', 'id_operacao']);
            $table->index(['store_id', 'vendedor_pdv_id']);
            $table->index(['store_id', 'id_produto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_venda_itens');
    }
};
