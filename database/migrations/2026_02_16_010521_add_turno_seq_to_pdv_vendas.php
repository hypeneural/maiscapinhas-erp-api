<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pdv_vendas', function (Blueprint $table) {
            if (!Schema::hasColumn('pdv_vendas', 'turno_seq')) {
                $table->integer('turno_seq')->nullable()->after('id_turno');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pdv_vendas', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_vendas', 'turno_seq')) {
                $table->dropColumn('turno_seq');
            }
        });
    }
};
