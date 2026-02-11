<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_syncs', function (Blueprint $table) {
            if (!Schema::hasColumn('pdv_syncs', 'event_type')) {
                $table->string('event_type', 30)->default('sales')->after('schema_version');
                $table->index('event_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdv_syncs', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_syncs', 'event_type')) {
                $table->dropIndex(['event_type']);
                $table->dropColumn('event_type');
            }
        });
    }
};

