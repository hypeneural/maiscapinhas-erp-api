<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_vendas', function (Blueprint $table) {
            if (!Schema::hasColumn('pdv_vendas', 'status')) {
                // Placing it after erp_loja_uuid if it exists, otherwise it will just append
                $table->string('status', 20)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdv_vendas', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_vendas', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
