<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bonus_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->date('effective_from');
            $table->json('config_json');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['store_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_rules');
    }
};
