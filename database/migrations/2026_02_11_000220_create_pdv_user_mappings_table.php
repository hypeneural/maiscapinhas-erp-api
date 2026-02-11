<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_user_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_pdv_id');
            $table->unsignedBigInteger('pdv_user_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->string('source', 40)->default('manual');
            $table->unsignedTinyInteger('confidence')->default(100);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['store_pdv_id', 'pdv_user_id'], 'pdv_user_mappings_unique_key');
            $table->index(['store_pdv_id', 'active'], 'pdv_user_mappings_idx_store_active');
            $table->index(['store_pdv_id', 'user_id'], 'pdv_user_mappings_idx_store_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_user_mappings');
    }
};

