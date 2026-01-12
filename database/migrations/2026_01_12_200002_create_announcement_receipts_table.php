<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('announcement_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('seen_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('dismissed_at')->nullable();
            $table->dateTime('last_shown_at')->nullable();
            $table->integer('show_count')->default(0);
            $table->dateTime('snooze_until')->nullable();
            $table->timestamps();

            // Unique constraint: one receipt per user per announcement
            $table->unique(['announcement_id', 'user_id'], 'uniq_receipts_announcement_user');

            // Indexes for query performance
            $table->index('user_id', 'idx_receipts_user');
            $table->index('announcement_id', 'idx_receipts_announcement');
            $table->index('acknowledged_at', 'idx_receipts_acknowledged');
            $table->index('last_shown_at', 'idx_receipts_last_shown');
            $table->index('dismissed_at', 'idx_receipts_dismissed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_receipts');
    }
};
