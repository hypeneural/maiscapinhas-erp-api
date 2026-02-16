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
            // Drop old unique constraint if it exists (check first or catch exception would be safer but checking key name is standard)
            // We know the key name is 'pdv_user_mappings_unique_pdv_user_id' from SHOW CREATE TABLE
            $table->dropUnique('pdv_user_mappings_unique_pdv_user_id');

            // Add new composite unique constraint
            $table->unique(['store_pdv_id', 'pdv_user_id'], 'pdv_user_mappings_unique_store_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pdv_user_mappings', function (Blueprint $table) {
            $table->dropUnique('pdv_user_mappings_unique_store_user');
            $table->unique('pdv_user_id', 'pdv_user_mappings_unique_pdv_user_id');
        });
    }
};
