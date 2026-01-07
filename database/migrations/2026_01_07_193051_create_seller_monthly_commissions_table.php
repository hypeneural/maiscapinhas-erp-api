<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_monthly_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('month', 7); // YYYY-MM format
            $table->decimal('sales_total', 10, 2)->default(0);
            $table->decimal('goal_amount', 10, 2)->default(0);
            $table->decimal('attainment_percent', 6, 2)->default(0);
            $table->decimal('rate_percent', 5, 2)->default(0);
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->enum('status', ['provisional', 'confirmed'])->default('provisional');
            $table->unsignedInteger('rule_version')->default(1);
            $table->timestamps();

            $table->unique(['store_id', 'user_id', 'month']);
            $table->index(['store_id', 'month']);
            $table->index(['user_id', 'month']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_monthly_commissions');
    }
};
