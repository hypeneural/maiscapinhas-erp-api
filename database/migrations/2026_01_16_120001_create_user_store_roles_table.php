<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create user_roles table for store-specific role assignments.
 * This extends the Spatie model_has_roles table to support store context.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_store_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete()
                ->comment('NULL = role global, ID = role apenas nesta loja');
            $table->timestamps();

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->unique(['user_id', 'role_id', 'store_id']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_store_roles');
    }
};
