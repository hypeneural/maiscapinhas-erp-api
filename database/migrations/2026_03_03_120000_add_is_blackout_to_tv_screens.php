<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tv_screens', function (Blueprint $table) {
            $table->boolean('is_blackout')->default(false)->after('is_online');
        });
    }

    public function down(): void
    {
        Schema::table('tv_screens', function (Blueprint $table) {
            $table->dropColumn('is_blackout');
        });
    }
};
