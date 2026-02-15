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
        Schema::table('pdv_user_mappings', function (Blueprint $table) {
            $table->string('guid_usuario', 36)->nullable()->after('user_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pdv_user_mappings', function (Blueprint $table) {
            $table->dropColumn('guid_usuario');
        });
    }
};
