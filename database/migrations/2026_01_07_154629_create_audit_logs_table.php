<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // e.g., "submit", "approve", "reject", "update"
            $table->string('entity_type'); // e.g., "CashClosing", "BonusRule"
            $table->unsignedBigInteger('entity_id');
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('actor_id');
            $table->index(['entity_type', 'entity_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
