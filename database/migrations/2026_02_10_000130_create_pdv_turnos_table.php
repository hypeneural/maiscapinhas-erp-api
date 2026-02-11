<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_turnos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_pdv_id');
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('id_turno', 64);
            $table->unsignedSmallInteger('sequencial')->nullable();
            $table->boolean('fechado')->default(false);
            $table->dateTime('data_hora_inicio')->nullable();
            $table->dateTime('data_hora_termino')->nullable();
            $table->unsignedBigInteger('operador_pdv_id')->nullable();
            $table->string('operador_nome', 200)->nullable();
            $table->decimal('total_sistema', 14, 2)->default(0);
            $table->unsignedInteger('qtd_vendas_sistema')->default(0);
            $table->decimal('total_declarado', 14, 2)->nullable();
            $table->decimal('total_falta', 14, 2)->nullable();
            $table->string('last_sync_id', 128)->nullable();
            $table->dateTime('last_window_to')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['store_pdv_id', 'id_turno']);
            $table->index(['store_pdv_id', 'data_hora_inicio']);
            $table->index(['store_id', 'data_hora_inicio']);
            $table->index(['store_pdv_id', 'fechado']);
            $table->index('last_sync_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_turnos');
    }
};
