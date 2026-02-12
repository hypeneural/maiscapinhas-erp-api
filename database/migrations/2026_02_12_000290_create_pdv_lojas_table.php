<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_lojas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_ponto_venda')->unique();
            $table->string('nome_padronizado', 200);
            $table->string('nome_hiper', 200)->nullable();
            $table->string('alias', 100)->nullable();
            $table->boolean('ativa')->default(true);
            $table->string('fonte', 50)->default('HIPER');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('ativa');
            $table->index('alias');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_lojas');
    }
};
