<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('sold_at');
            $table->decimal('amount', 10, 2);
            $table->enum('source', ['pdv', 'manual'])->default('pdv');
            $table->timestamps();

            $table->index('store_id');
            $table->index('seller_id');
            $table->index('sold_at');
            $table->index(['store_id', 'sold_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
