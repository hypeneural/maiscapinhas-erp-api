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
        Schema::table('stores', function (Blueprint $table) {
            // Check if columns exist before adding (safety check)
            if (!Schema::hasColumn('stores', 'guid')) {
                $table->uuid('guid')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('stores', 'razao_social')) {
                $table->string('razao_social', 255)->nullable()->after('cnpj');
            }
            if (!Schema::hasColumn('stores', 'nome_fantasia')) {
                $table->string('nome_fantasia', 255)->nullable()->after('razao_social');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'guid')) {
                $table->uuid('guid')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('users', 'erp_id')) {
                $table->unsignedBigInteger('erp_id')->nullable()->unique()->after('guid');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['guid', 'razao_social', 'nome_fantasia']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['guid', 'erp_id']);
        });
    }
};
