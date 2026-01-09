<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds justification fields to the cash_closings table.
     * - justification_text: Optional text explanation for divergences (single for entire shift)
     * - justified: Boolean flag to indicate if divergence was justified (used for bonus calculation)
     */
    public function up(): void
    {
        Schema::table('cash_closings', function (Blueprint $table) {
            $table->text('justification_text')->nullable()->after('version');
            $table->boolean('justified')->default(false)->after('justification_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_closings', function (Blueprint $table) {
            $table->dropColumn(['justification_text', 'justified']);
        });
    }
};
