<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_venda_pagamentos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_pdv_id');
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->unsignedBigInteger('id_operacao');
            $table->unsignedInteger('line_no');
            $table->unsignedBigInteger('id_finalizador')->default(0);
            $table->string('meio_pagamento', 120)->nullable();
            $table->decimal('valor', 14, 2)->default(0);
            $table->decimal('troco', 14, 2)->default(0);
            $table->unsignedSmallInteger('parcelas')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['store_pdv_id', 'id_operacao', 'line_no'], 'pdv_venda_pagamentos_unique_line');
            $table->index(['store_pdv_id', 'id_operacao']);
            $table->index(['store_id', 'id_finalizador']);
            $table->index(['store_id', 'meio_pagamento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_venda_pagamentos');
    }
};
