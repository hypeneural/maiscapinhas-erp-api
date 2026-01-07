<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cash_closing_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_closing_id')->constrained('cash_closings')->cascadeOnDelete();
            $table->string('label'); // e.g., "Dinheiro", "Cartão Crédito", "Cartão Débito", "PIX"
            $table->decimal('system_value', 10, 2)->default(0);
            $table->decimal('real_value', 10, 2)->default(0);
            $table->decimal('diff_value', 10, 2)->default(0);
            $table->text('justification_text')->nullable();
            $table->timestamps();

            $table->index('cash_closing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closing_lines');
    }
};
