<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tv_screen_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->foreignId('screen_id')->constrained('tv_screens')->cascadeOnDelete();
            $table->string('screen_slug', 80)->index();
            $table->dateTime('started_at');
            $table->dateTime('last_ping_at');
            $table->dateTime('ended_at')->nullable();
            $table->integer('duration_minutes')->default(0);
            $table->string('ended_reason', 50)->nullable(); // standby_turned_off, timeout_disconnected, browser_closed
            $table->json('device_info')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'started_at']);
            $table->index(['screen_id', 'started_at']);
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_screen_sessions');
    }
};
