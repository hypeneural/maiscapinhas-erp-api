<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_daily_bonus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('sales_total', 10, 2)->default(0);
            $table->decimal('divergence_total', 10, 2)->default(0);
            $table->boolean('eligible')->default(false);
            $table->decimal('bonus_amount', 10, 2)->default(0);
            $table->enum('status', ['provisional', 'confirmed', 'zeroed'])->default('provisional');
            $table->unsignedInteger('rule_version')->default(1);
            $table->timestamps();

            $table->unique(['store_id', 'user_id', 'date']);
            $table->index(['store_id', 'date']);
            $table->index(['user_id', 'date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_daily_bonus');
    }
};
