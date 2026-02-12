<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_meios_pagamento', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_finalizador')->unique();
            $table->string('nome_padronizado', 100);
            $table->string('nome_hiper', 100)->nullable();
            $table->string('categoria', 50)->nullable();
            $table->boolean('ativo')->default(true);
            $table->string('fonte', 50)->default('HIPER');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('ativo');
            $table->index('categoria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_meios_pagamento');
    }
};
