<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Telas / Dispositivos das TVs por Loja
        Schema::create('tv_screens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80)->unique(); // Ex: loja-01, morretes-tv
            $table->string('screen_key', 64)->unique(); // Chave alfanumérica única
            $table->string('access_token', 128)->nullable();
            $table->string('orientation', 20)->default('auto'); // auto, horizontal, vertical
            $table->boolean('is_online')->default(false);
            $table->dateTime('last_ping_at')->nullable();
            $table->json('device_info')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'slug']);
            $table->index('is_online');
        });

        // 2. Anúncios de TV (Fotos e Vídeos)
        Schema::create('tv_ads', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('media_type', 20)->default('image'); // image, video
            $table->string('media_url', 500);
            $table->string('media_path', 500)->nullable();
            $table->string('thumbnail_url', 500)->nullable();
            $table->integer('duration_seconds')->default(10);
            $table->string('target_orientation', 20)->default('all'); // all, horizontal, vertical
            $table->boolean('is_global')->default(true); // true = todas as lojas
            $table->string('status', 20)->default('active'); // active, inactive, scheduled, expired
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->json('days_of_week')->nullable(); // [0,1,2,3,4,5,6] (0 = domingo)
            $table->integer('order_priority')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at', 'ends_at'], 'idx_tv_ads_schedule');
            $table->index(['is_global', 'status']);
            $table->index('order_priority');
        });

        // 3. Segmentação de Anúncios por Loja Específica (N:N)
        Schema::create('tv_ad_store_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tv_ad_id')->constrained('tv_ads')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tv_ad_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_ad_store_targets');
        Schema::dropIfExists('tv_ads');
        Schema::dropIfExists('tv_screens');
    }
};
