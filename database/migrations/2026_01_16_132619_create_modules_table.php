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
        Schema::create('modules', function (Blueprint $table) {
            $table->string('id', 50)->primary(); // 'pedidos-simples'
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('version', 20)->default('1.0.0');
            $table->string('icon', 50)->nullable();
            $table->boolean('is_core')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable(); // Custom configuration
            $table->json('status_overrides')->nullable(); // Super Admin customized statuses
            $table->json('transition_overrides')->nullable(); // Super Admin customized transitions
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('module_store', function (Blueprint $table) {
            $table->id();
            $table->string('module_id', 50);
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable(); // Store-specific config
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->foreign('module_id')->references('id')->on('modules')->onDelete('cascade');
            $table->unique(['module_id', 'store_id']);
        });

        Schema::create('module_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('module_id', 50);
            $table->foreignId('permission_id')->constrained()->onDelete('cascade');
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->foreign('module_id')->references('id')->on('modules')->onDelete('cascade');
            $table->unique(['module_id', 'permission_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_permissions');
        Schema::dropIfExists('module_store');
        Schema::dropIfExists('modules');
    }
};
