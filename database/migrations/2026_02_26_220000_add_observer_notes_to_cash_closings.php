<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add observer_notes field to cash_closings table.
 *
 * Separates seller justification (justification_text) from
 * conference observer notes (observer_notes).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('cash_closings', function (Blueprint $table) {
            $table->text('observer_notes')->nullable()->after('justified');
        });
    }

    public function down(): void
    {
        Schema::table('cash_closings', function (Blueprint $table) {
            $table->dropColumn('observer_notes');
        });
    }
};
