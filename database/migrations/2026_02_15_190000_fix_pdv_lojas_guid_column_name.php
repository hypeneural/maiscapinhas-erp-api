<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_lojas', function (Blueprint $table) {
            // Rename 'guid' to 'guid_loja' if 'guid' exists and 'guid_loja' does not
            if (Schema::hasColumn('pdv_lojas', 'guid') && !Schema::hasColumn('pdv_lojas', 'guid_loja')) {
                $table->renameColumn('guid', 'guid_loja');
            }
            // If neither exists (edge case), create guid_loja
            elseif (!Schema::hasColumn('pdv_lojas', 'guid') && !Schema::hasColumn('pdv_lojas', 'guid_loja')) {
                $table->string('guid_loja', 36)->nullable()->unique()->after('alias');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdv_lojas', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_lojas', 'guid_loja')) {
                $table->renameColumn('guid_loja', 'guid');
            }
        });
    }
};
