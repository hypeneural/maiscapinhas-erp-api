<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_syncs', function (Blueprint $table) {
            if (!Schema::hasColumn('pdv_syncs', 'schema_version')) {
                $table->string('schema_version', 10)->default('2.0')->after('sync_id');
                $table->index('schema_version');
            }

            if (!Schema::hasColumn('pdv_syncs', 'request_id')) {
                $table->string('request_id', 64)->nullable()->after('agent_machine');
                $table->index('request_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdv_syncs', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_syncs', 'schema_version')) {
                $table->dropIndex(['schema_version']);
                $table->dropColumn('schema_version');
            }

            if (Schema::hasColumn('pdv_syncs', 'request_id')) {
                $table->dropIndex(['request_id']);
                $table->dropColumn('request_id');
            }
        });
    }
};
