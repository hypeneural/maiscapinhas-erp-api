<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('targets_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('target_amount', 10, 2);
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'date']);
            $table->index(['store_id', 'date', 'seller_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('targets_daily');
    }
};
