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
        Schema::table('pdv_store_mappings', function (Blueprint $table) {
            $table->string('guid_loja', 36)->nullable()->after('alias')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pdv_store_mappings', function (Blueprint $table) {
            $table->dropColumn('guid_loja');
        });
    }
};
