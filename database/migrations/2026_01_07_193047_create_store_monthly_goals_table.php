<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('store_monthly_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('month', 7); // YYYY-MM format
            $table->decimal('goal_amount', 10, 2);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_monthly_goals');
    }
};
