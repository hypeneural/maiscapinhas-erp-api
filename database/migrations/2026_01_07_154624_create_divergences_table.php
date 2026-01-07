<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('divergences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_closing_line_id')->constrained('cash_closing_lines')->cascadeOnDelete();
            $table->enum('status', ['pending', 'resolved'])->default('pending');
            $table->boolean('justification_required')->default(true);
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('divergences');
    }
};
