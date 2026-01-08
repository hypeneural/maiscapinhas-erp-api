<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona campos de contexto à tabela audit_logs para rastreabilidade.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('request_id', 36)->nullable()->after('id');
            $table->string('ip', 45)->nullable()->after('request_id');
            $table->text('user_agent')->nullable()->after('ip');
            $table->foreignId('store_id')->nullable()->after('actor_id')->constrained('stores')->nullOnDelete();
            $table->string('log_name', 50)->default('default')->after('action');
            $table->string('event', 100)->nullable()->after('log_name');

            $table->index('request_id');
            $table->index('store_id');
            $table->index('log_name');
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropIndex(['store_id']);
            $table->dropIndex(['log_name']);
            $table->dropIndex(['event']);

            $table->dropColumn(['request_id', 'ip', 'user_agent', 'store_id', 'log_name', 'event']);
        });
    }
};
