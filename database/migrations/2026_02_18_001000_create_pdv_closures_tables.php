<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create pdv_closures table — 1 row per physical cash closure.
 *
 * This is the canonical "fact" table for closures, aggregated from
 * the per-channel pdv_turnos + pdv_turno_pagamentos data.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_closures', function (Blueprint $table) {
            $table->string('closure_uuid', 36)->primary();

            $table->unsignedBigInteger('store_pdv_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('store_loja_guid', 36)->nullable()->comment('UUID loja no Hiper');

            $table->integer('sequencial')->nullable();
            $table->string('periodo', 30)->nullable();

            // Operador que fez o fechamento
            $table->string('operador_nome')->nullable();
            $table->string('operador_guid', 36)->nullable();
            $table->unsignedBigInteger('operador_hiper_id')->nullable();

            // Datas
            $table->dateTime('data_hora_fechamento')->nullable();
            $table->dateTime('inicio_min')->nullable()->comment('Min data_hora_inicio entre canais');
            $table->dateTime('termino_max')->nullable()->comment('Max data_hora_termino entre canais');

            // Canais presentes
            $table->json('canais_presentes')->nullable()->comment('["HIPER_CAIXA","HIPER_LOJA"]');
            $table->string('canal_canonico', 20)->nullable()->comment('Canal usado como referência');

            // Totais unificados
            $table->decimal('total_sistema_caixa', 12, 2)->default(0.00);
            $table->decimal('total_sistema_loja', 12, 2)->default(0.00);
            $table->decimal('total_sistema_unificado', 12, 2)->default(0.00);
            $table->decimal('total_declarado', 12, 2)->nullable();
            $table->decimal('total_falta', 12, 2)->nullable();
            $table->decimal('total_sobra', 12, 2)->nullable();

            // Consistência
            $table->boolean('declared_consistent')->default(true);
            $table->boolean('has_loja_sales')->default(false);

            // Status
            $table->string('status', 30)->default('closed_local')
                ->comment('partial|closed_local|validated_online|divergent');

            $table->unsignedBigInteger('last_sync_id')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('store_pdv_id');
            $table->index('store_id');
            $table->index(['store_pdv_id', 'data_hora_fechamento']);
            $table->index('status');
        });

        // Payments linked to a closure (declarado/falta/sobra — canonical only)
        Schema::create('pdv_closure_pagamentos', function (Blueprint $table) {
            $table->id();
            $table->string('closure_uuid', 36);
            $table->string('tipo', 20)->comment('declarado|falta|sobra');
            $table->unsignedInteger('id_finalizador')->default(0);
            $table->string('meio_pagamento', 100)->nullable();
            $table->decimal('total', 12, 2)->default(0.00);
            $table->integer('qtd_vendas')->default(0);
            $table->timestamps();

            $table->foreign('closure_uuid')
                ->references('closure_uuid')
                ->on('pdv_closures')
                ->cascadeOnDelete();

            $table->index(['closure_uuid', 'tipo']);
        });

        // Cash rules per store
        Schema::create('pdv_cash_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_pdv_id')->unique();
            $table->boolean('include_loja_sales_in_cash')->default(false)
                ->comment('Quando true, total_cash_recomendado = caixa + loja');
            $table->json('extra_config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_closure_pagamentos');
        Schema::dropIfExists('pdv_closures');
        Schema::dropIfExists('pdv_cash_rules');
    }
};
