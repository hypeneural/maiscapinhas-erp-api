<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('people_kpi_shift', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->date('date');
            $table->char('shift_code', 1); // M, T, N
            $table->unsignedInteger('in_count')->default(0);
            $table->unsignedInteger('out_count')->default(0);
            $table->unsignedInteger('staff_in')->default(0);
            $table->unsignedInteger('staff_out')->default(0);
            $table->enum('source', ['fastapi', 'manual'])->default('manual');
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'date', 'shift_code']);
            $table->index(['store_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people_kpi_shift');
    }
};
