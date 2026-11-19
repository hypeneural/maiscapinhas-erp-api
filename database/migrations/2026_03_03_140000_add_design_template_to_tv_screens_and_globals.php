<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('tv_screens', 'design_template')) {
            Schema::table('tv_screens', function (Blueprint $table) {
                $table->json('design_template')->nullable()->after('device_info');
            });
        }

        if (!Schema::hasTable('tv_global_settings')) {
            Schema::create('tv_global_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 80)->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tv_screens', 'design_template')) {
            Schema::table('tv_screens', function (Blueprint $table) {
                $table->dropColumn('design_template');
            });
        }

        Schema::dropIfExists('tv_global_settings');
    }
};
