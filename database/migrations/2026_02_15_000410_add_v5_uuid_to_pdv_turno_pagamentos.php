<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_turno_pagamentos', function (Blueprint $table) {
            $table->string('pagamento_uuid', 36)->nullable()->after('qtd_vendas');
            $table->string('operacao_uuid', 36)->nullable()->after('pagamento_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('pdv_turno_pagamentos', function (Blueprint $table) {
            $table->dropColumn(['pagamento_uuid', 'operacao_uuid']);
        });
    }
};
