<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Enrich tv_screens with deterministic health state & telemetry metadata
        Schema::table('tv_screens', function (Blueprint $table) {
            if (!Schema::hasColumn('tv_screens', 'health_status')) {
                $table->string('health_status', 24)->default('never_connected')->after('is_blackout');
                $table->index('health_status', 'idx_tv_screens_health_status');
            }
            if (!Schema::hasColumn('tv_screens', 'status_changed_at')) {
                $table->dateTime('status_changed_at')->nullable()->after('health_status');
                $table->index('status_changed_at', 'idx_tv_screens_status_changed_at');
            }
            if (!Schema::hasColumn('tv_screens', 'online_since')) {
                $table->dateTime('online_since')->nullable()->after('status_changed_at');
            }
            if (!Schema::hasColumn('tv_screens', 'offline_since')) {
                $table->dateTime('offline_since')->nullable()->after('online_since');
            }
            if (!Schema::hasColumn('tv_screens', 'last_device_id')) {
                $table->string('last_device_id', 64)->nullable()->after('offline_since');
            }
            if (!Schema::hasColumn('tv_screens', 'playlist_version')) {
                $table->string('playlist_version', 64)->nullable()->after('last_device_id');
            }
            if (!Schema::hasColumn('tv_screens', 'monitoring_enabled')) {
                $table->boolean('monitoring_enabled')->default(true)->after('playlist_version');
            }
        });

        // 2. Immutable status transition & health event history per TV
        Schema::create('tv_screen_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screen_id')->constrained('tv_screens')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('screen_slug', 80)->index();
            $table->string('event_type', 50)->index();
            $table->string('previous_status', 24)->nullable();
            $table->string('new_status', 24);
            $table->string('reason', 80)->nullable();
            $table->unsignedBigInteger('duration_previous_seconds')->nullable();
            $table->boolean('during_business_hours')->default(false);
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['screen_id', 'occurred_at'], 'idx_tv_status_events_screen_time');
            $table->index(['event_type', 'occurred_at'], 'idx_tv_status_events_type_time');
        });

        // 3. Configurable outbound webhooks for TV transitions & alerts
        Schema::create('tv_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('url', 500);
            $table->boolean('is_active')->default(true)->index();
            $table->json('events');
            $table->json('store_ids')->nullable();
            $table->boolean('only_during_business_hours')->default(false);
            $table->unsignedInteger('cooldown_seconds')->default(60);
            $table->json('custom_headers')->nullable();
            $table->dateTime('last_triggered_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 4. Auditable delivery log for every outbound webhook attempt
        Schema::create('tv_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_uuid', 36)->unique();
            $table->foreignId('tv_webhook_id')->nullable()->constrained('tv_webhooks')->nullOnDelete();
            $table->string('target_url', 500);
            $table->string('event_type', 50)->index();
            $table->string('screen_slug', 80)->nullable()->index();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('payload')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->dateTime('dispatched_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['tv_webhook_id', 'screen_slug', 'event_type', 'dispatched_at'], 'idx_tv_wh_deliv_cooldown');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_webhook_deliveries');
        Schema::dropIfExists('tv_webhooks');
        Schema::dropIfExists('tv_screen_status_events');

        Schema::table('tv_screens', function (Blueprint $table) {
            foreach (['monitoring_enabled', 'playlist_version', 'last_device_id', 'offline_since', 'online_since', 'status_changed_at', 'health_status'] as $col) {
                if (Schema::hasColumn('tv_screens', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
