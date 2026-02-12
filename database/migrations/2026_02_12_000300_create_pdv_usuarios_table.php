<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_usuarios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_usuario_hiper')->unique();
            $table->string('nome_padronizado', 200);
            $table->string('nome_hiper', 200)->nullable();
            $table->string('login_hiper', 100)->nullable();
            $table->string('papel', 50)->default('VENDEDOR');
            $table->boolean('ativo')->default(true);
            $table->string('fonte', 50)->default('HIPER');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('ativo');
            $table->index('papel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_usuarios');
    }
};
