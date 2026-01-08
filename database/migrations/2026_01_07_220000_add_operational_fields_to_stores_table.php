<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona campos operacionais à tabela stores:
 * - codigo: Código curto da loja (ex: TJC, ITP, BOM)
 * - troco_padrao: Valor padrão de troco inicial do caixa
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('codigo', 10)->nullable()->unique()->after('name');
            $table->decimal('troco_padrao', 10, 2)->default(500.00)->after('cnpj');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['codigo', 'troco_padrao']);
        });
    }
};
