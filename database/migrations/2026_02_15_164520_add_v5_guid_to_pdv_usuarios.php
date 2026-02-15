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
        Schema::table('pdv_usuarios', function (Blueprint $table) {
            // Using after 'login_hiper' if it exists, otherwise just appending.
            // Since we can't easily check for column position here safely without DB call,
            // we rely on Laravel to append or place it if 'after' is supported and column exists.
            $table->string('guid', 36)->nullable()->after('updated_at');
            $table->string('email', 255)->nullable()->after('guid');
            $table->string('documento', 50)->nullable()->after('email');
            $table->string('tipo', 20)->nullable()->after('documento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pdv_usuarios', function (Blueprint $table) {
            $table->dropColumn(['guid', 'email', 'documento', 'tipo']);
        });
    }
};
