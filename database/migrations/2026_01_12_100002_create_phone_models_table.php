<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('phone_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('phone_brands')->cascadeOnDelete();
            $table->string('marketing_name');
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->enum('form_factor', ['smartphone', 'tablet', 'watch', 'feature_phone'])->default('smartphone');
            $table->timestamps();

            // Indexes
            $table->index(['brand_id', 'marketing_name', 'release_year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phone_models');
    }
};
