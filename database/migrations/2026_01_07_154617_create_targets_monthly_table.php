<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('targets_monthly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->char('month', 7); // YYYY-MM format
            $table->decimal('target_amount', 10, 2);
            $table->timestamps();

            $table->index(['store_id', 'month']);
            $table->unique(['store_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('targets_monthly');
    }
};
