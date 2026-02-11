<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('sync_id', 128)->unique();
            $table->unsignedBigInteger('store_pdv_id');
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('store_alias', 100)->nullable();
            $table->dateTime('window_from');
            $table->dateTime('window_to');
            $table->string('agent_version', 20)->nullable();
            $table->string('agent_machine', 120)->nullable();
            $table->unsignedInteger('ops_count')->default(0);
            $table->json('warnings')->nullable();

            $table->string('status', 20)->default('received');
            $table->unsignedInteger('timestamp_skew_seconds')->nullable();
            $table->boolean('timestamp_out_of_window')->default(false);
            $table->json('risk_flags')->nullable();

            $table->char('payload_sha256', 64);
            $table->unsignedInteger('payload_bytes');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->dateTime('received_at');
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['store_pdv_id', 'received_at']);
            $table->index(['store_id', 'received_at']);
            $table->index('status');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_syncs');
    }
};
