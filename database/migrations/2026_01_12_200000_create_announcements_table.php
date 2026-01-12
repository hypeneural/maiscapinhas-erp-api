<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('message');
            $table->string('excerpt', 200)->nullable();
            $table->string('type')->default('recado'); // recado, advertencia
            $table->string('severity')->default('info'); // info, warning, danger
            $table->string('display_mode')->default('banner'); // banner, modal, both
            $table->string('icon', 50)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('image_alt', 120)->nullable();
            $table->string('cta_label', 80)->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->string('scope')->default('global'); // global, store, user, role
            $table->boolean('require_ack')->default(false);
            $table->string('status')->default('draft'); // draft, scheduled, active, expired, archived
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->integer('repeat_every_minutes')->nullable();
            $table->integer('priority')->default(0);
            $table->dateTime('pinned_until')->nullable();
            $table->json('meta_json')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users');
            $table->dateTime('published_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users');
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for query performance
            $table->index(['status', 'starts_at', 'expires_at'], 'idx_announcements_schedule');
            $table->index('scope', 'idx_announcements_scope');
            $table->index(['severity', 'require_ack'], 'idx_announcements_severity_ack');
            $table->index(['priority', 'pinned_until'], 'idx_announcements_priority');
            $table->index('published_at', 'idx_announcements_published');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
