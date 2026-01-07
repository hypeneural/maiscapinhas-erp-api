<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->date('date');
            $table->string('shift_code'); // e.g., "M" (manhã), "T" (tarde), "N" (noite)
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['open', 'closed', 'pending'])->default('open');
            $table->timestamps();

            $table->index(['store_id', 'date']);
            $table->index(['store_id', 'date', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_shifts');
    }
};
