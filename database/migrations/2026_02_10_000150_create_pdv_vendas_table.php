<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_vendas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_pdv_id');
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->unsignedBigInteger('id_operacao');
            $table->string('id_turno', 64)->nullable();
            $table->dateTime('data_hora')->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->string('sync_id', 128)->nullable();
            $table->dateTime('last_window_to')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['store_pdv_id', 'id_operacao']);
            $table->index(['store_pdv_id', 'data_hora']);
            $table->index(['store_id', 'data_hora']);
            $table->index(['store_pdv_id', 'id_turno']);
            $table->index('sync_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_vendas');
    }
};
