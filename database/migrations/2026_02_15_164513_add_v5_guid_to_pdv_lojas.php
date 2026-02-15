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
        Schema::table('pdv_lojas', function (Blueprint $table) {
            $table->string('guid', 36)->nullable()->after('alias');
            $table->unsignedBigInteger('id_hiper')->nullable()->after('guid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pdv_lojas', function (Blueprint $table) {
            $table->dropColumn(['guid', 'id_hiper']);
        });
    }
};
