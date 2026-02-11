<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_turno_pagamentos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_pdv_id');
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('id_turno', 64);
            $table->string('tipo', 20);
            $table->unsignedBigInteger('id_finalizador')->default(0);
            $table->string('meio_pagamento', 120)->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->unsignedInteger('qtd_vendas')->default(0);
            $table->string('last_sync_id', 128)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['store_pdv_id', 'id_turno', 'tipo', 'id_finalizador'], 'pdv_turno_pagamentos_unique_key');
            $table->index(['store_pdv_id', 'id_turno', 'tipo']);
            $table->index(['store_id', 'tipo']);
            $table->index('last_sync_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_turno_pagamentos');
    }
};
