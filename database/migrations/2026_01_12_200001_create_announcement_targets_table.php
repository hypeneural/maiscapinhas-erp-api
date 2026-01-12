<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('announcement_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->string('target_type'); // store, user, role
            $table->string('target_id', 64);
            $table->timestamp('created_at')->nullable();

            // Indexes
            $table->index('announcement_id', 'idx_targets_announcement');
            $table->index(['target_type', 'target_id'], 'idx_targets_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_targets');
    }
};
