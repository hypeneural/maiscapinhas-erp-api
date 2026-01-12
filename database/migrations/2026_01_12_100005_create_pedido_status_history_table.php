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
        Schema::create('pedido_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->tinyInteger('old_status')->nullable();
            $table->tinyInteger('new_status');
            $table->foreignId('changed_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 30)->nullable(); // api, bulk, system
            $table->string('reason')->nullable();
            $table->json('meta_json')->nullable(); // ip, user_agent, request_id

            // Indexes
            $table->index(['pedido_id', 'changed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedido_status_history');
    }
};
