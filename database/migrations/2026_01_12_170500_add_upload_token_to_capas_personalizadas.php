<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('capas_personalizadas', function (Blueprint $table) {
            $table->string('upload_token', 64)->nullable()->after('photo_path');
            $table->timestamp('upload_token_expires_at')->nullable()->after('upload_token');
            
            $table->index('upload_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capas_personalizadas', function (Blueprint $table) {
            $table->dropIndex(['upload_token']);
            $table->dropColumn(['upload_token', 'upload_token_expires_at']);
        });
    }
};
