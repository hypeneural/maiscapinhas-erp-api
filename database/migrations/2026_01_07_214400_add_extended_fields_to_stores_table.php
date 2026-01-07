<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona campos estendidos à tabela stores:
 * - photo_url: URL da foto da loja
 * - address: Endereço completo
 * - neighborhood: Bairro
 * - state: Estado (UF)
 * - zip_code: CEP
 * - latitude/longitude: Coordenadas GPS
 * - phone: Telefone fixo
 * - whatsapp: WhatsApp da loja
 * - instagram: Instagram da loja
 * - opening_hours: Horário de funcionamento (JSON)
 * - cnpj: CNPJ da loja
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Foto
            $table->string('photo_url', 500)->nullable()->after('city');

            // Endereço completo
            $table->string('address', 200)->nullable()->after('photo_url');
            $table->string('neighborhood', 100)->nullable()->after('address');
            $table->char('state', 2)->nullable()->after('neighborhood');
            $table->string('zip_code', 10)->nullable()->after('state');

            // GPS
            $table->decimal('latitude', 10, 8)->nullable()->after('zip_code');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');

            // Contato
            $table->string('phone', 20)->nullable()->after('longitude');
            $table->string('whatsapp', 20)->nullable()->after('phone');
            $table->string('instagram', 50)->nullable()->after('whatsapp');

            // Horário de funcionamento (JSON com dias da semana)
            $table->json('opening_hours')->nullable()->after('instagram');

            // CNPJ
            $table->string('cnpj', 18)->nullable()->after('opening_hours');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'photo_url',
                'address',
                'neighborhood',
                'state',
                'zip_code',
                'latitude',
                'longitude',
                'phone',
                'whatsapp',
                'instagram',
                'opening_hours',
                'cnpj',
            ]);
        });
    }
};
