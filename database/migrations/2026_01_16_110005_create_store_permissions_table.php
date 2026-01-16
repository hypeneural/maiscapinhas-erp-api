<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('store_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('permission_id');
            $table->boolean('granted')->default(true)
                ->comment('true = libera para toda a loja, false = nega para toda a loja');
            $table->timestamps();

            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->unique(['store_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_permission_overrides');
    }
};
