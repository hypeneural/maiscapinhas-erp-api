<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_store_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pdv_store_id')->unique();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->string('alias', 100)->nullable();
            $table->boolean('active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_store_mappings');
    }
};
