<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Torna as colunas entity_type e entity_id nullable.
 * 
 * Isso é necessário para eventos de sistema como auth.login_failed
 * onde não há uma entidade específica (subject) associada ao evento.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('entity_type')->nullable()->change();
            $table->unsignedBigInteger('entity_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Revert: mas cuidado - isso pode falhar se houver registros com null
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('entity_type')->nullable(false)->change();
            $table->unsignedBigInteger('entity_id')->nullable(false)->change();
        });
    }
};
