<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_venda_itens', function (Blueprint $table) {
            $table->string('vendedor_guid', 36)->nullable()->after('vendedor_nome');
            $table->unsignedBigInteger('vendedor_hiper_id')->nullable()->after('vendedor_guid');
            $table->index('vendedor_guid', 'pdv_venda_itens_idx_vendedor_guid');
        });
    }

    public function down(): void
    {
        Schema::table('pdv_venda_itens', function (Blueprint $table) {
            $table->dropIndex('pdv_venda_itens_idx_vendedor_guid');
            $table->dropColumn(['vendedor_guid', 'vendedor_hiper_id']);
        });
    }
};
