<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_usuarios', function (Blueprint $table) {
            // Check if 'guid' exists and 'guid_usuario' does not, then rename.
            if (Schema::hasColumn('pdv_usuarios', 'guid') && !Schema::hasColumn('pdv_usuarios', 'guid_usuario')) {
                $table->renameColumn('guid', 'guid_usuario');
            }
            // If neither exists (edge case), create 'guid_usuario'.
            elseif (!Schema::hasColumn('pdv_usuarios', 'guid_usuario')) {
                $table->string('guid_usuario', 36)->nullable()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdv_usuarios', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_usuarios', 'guid_usuario')) {
                $table->renameColumn('guid_usuario', 'guid');
            }
        });
    }
};
