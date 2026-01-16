<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend Spatie Permission tables with additional columns for granular permissions.
 */
return new class extends Migration {
    public function up(): void
    {
        // Add columns to permissions table
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('display_name', 150)->nullable()->after('name');
            $table->enum('type', ['ability', 'screen', 'feature'])->default('ability')->after('display_name');
            $table->string('module', 50)->nullable()->after('type');
            $table->text('description')->nullable()->after('module');
            $table->integer('sort_order')->default(0)->after('description');

            $table->index(['type', 'module']);
        });

        // Add columns to roles table
        Schema::table('roles', function (Blueprint $table) {
            $table->string('display_name', 100)->nullable()->after('name');
            $table->text('description')->nullable()->after('display_name');
            $table->integer('level')->default(0)->after('description');
            $table->boolean('is_system')->default(false)->after('level');

            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex(['type', 'module']);
            $table->dropColumn(['display_name', 'type', 'module', 'description', 'sort_order']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['level']);
            $table->dropColumn(['display_name', 'description', 'level', 'is_system']);
        });
    }
};
