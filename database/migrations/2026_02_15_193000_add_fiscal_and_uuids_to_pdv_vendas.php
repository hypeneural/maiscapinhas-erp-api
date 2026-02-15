<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_vendas', function (Blueprint $table) {
            $table->string('erp_operacao_uuid', 36)->nullable()->after('id_operacao')->index();
            $table->string('erp_loja_uuid', 36)->nullable()->after('store_pdv_id')->index();

            $table->string('nfce_chave', 44)->nullable()->after('total')->index();
            $table->string('nfce_protocolo', 50)->nullable()->after('nfce_chave');
            $table->string('nfce_numero', 20)->nullable()->after('nfce_protocolo');
            $table->string('nfce_serie', 10)->nullable()->after('nfce_numero');
            $table->string('nfce_modelo', 10)->nullable()->after('nfce_serie');

            $table->string('nfe_chave', 44)->nullable()->after('nfce_modelo');

            $table->string('cliente_cpf', 20)->nullable()->after('nfe_chave');
            $table->string('signature_hash', 100)->nullable()->after('cliente_cpf');
        });
    }

    public function down(): void
    {
        Schema::table('pdv_vendas', function (Blueprint $table) {
            $table->dropColumn([
                'erp_operacao_uuid',
                'erp_loja_uuid',
                'nfce_chave',
                'nfce_protocolo',
                'nfce_numero',
                'nfce_serie',
                'nfce_modelo',
                'nfe_chave',
                'cliente_cpf',
                'signature_hash'
            ]);
        });
    }
};
