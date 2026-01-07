<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona campos estendidos à tabela users:
 * - birth_date: Data de nascimento
 * - hire_date: Data de entrada na empresa
 * - whatsapp: Número do WhatsApp
 * - avatar_url: URL da foto de perfil
 * - instagram: Username do Instagram
 * - cpf: CPF do funcionário
 * - pix_key: Chave PIX para pagamentos
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('email');
            $table->date('hire_date')->nullable()->after('birth_date');
            $table->string('whatsapp', 20)->nullable()->after('hire_date');
            $table->string('avatar_url', 500)->nullable()->after('whatsapp');
            $table->string('instagram', 50)->nullable()->after('avatar_url');
            $table->string('cpf', 14)->nullable()->after('instagram');
            $table->string('pix_key', 100)->nullable()->after('cpf');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'birth_date',
                'hire_date',
                'whatsapp',
                'avatar_url',
                'instagram',
                'cpf',
                'pix_key',
            ]);
        });
    }
};
