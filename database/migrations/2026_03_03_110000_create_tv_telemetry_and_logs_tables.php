<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Estatísticas de Impressões dos Anúncios por Loja (Telemetria)
        Schema::create('tv_ad_impressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tv_ad_id')->constrained('tv_ads')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('screen_slug', 80)->nullable();
            $table->integer('duration_played_seconds')->default(0);
            $table->boolean('completed_naturally')->default(true); // se tocou até o fim
            $table->string('media_type', 20)->default('image');
            $table->dateTime('displayed_at');
            $table->timestamps();

            $table->index(['tv_ad_id', 'displayed_at']);
            $table->index(['store_id', 'displayed_at']);
            $table->index('screen_slug');
        });

        // 2. Logs de Diagnóstico e Eventos em Tempo Real das TVs
        Schema::create('tv_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 20)->default('info'); // info, warning, error, sync
            $table->string('event', 80); // heartbeat, slide_change, upload_success, upload_error, stream_error
            $table->string('screen_slug', 80)->nullable();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('message', 255);
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['level', 'created_at']);
            $table->index(['screen_slug', 'event']);
        });

        // 3. Adicionar campos de telemetria em tv_screens
        Schema::table('tv_screens', function (Blueprint $table) {
            $table->string('current_ad_title', 150)->nullable()->after('is_online');
            $table->integer('current_ad_id')->nullable()->after('current_ad_title');
            $table->integer('fps')->nullable()->after('current_ad_id');
            $table->integer('uptime_seconds')->default(0)->after('fps');
        });
    }

    public function down(): void
    {
        Schema::table('tv_screens', function (Blueprint $table) {
            $table->dropColumn(['current_ad_title', 'current_ad_id', 'fps', 'uptime_seconds']);
        });
        Schema::dropIfExists('tv_logs');
        Schema::dropIfExists('tv_ad_impressions');
    }
};
