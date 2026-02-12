<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const LEGACY_UNIQUE = 'pdv_vendas_store_pdv_id_id_operacao_unique';
    private const CANONICAL_UNIQUE = 'pdv_vendas_store_pdv_id_canal_id_operacao_unique';
    private const CANAL_INDEX = 'pdv_vendas_canal_index';

    public function up(): void
    {
        Schema::table('pdv_vendas', function (Blueprint $table) {
            if (!Schema::hasColumn('pdv_vendas', 'canal')) {
                $table->string('canal', 20)->default('HIPER_CAIXA');
            }
        });

        DB::table('pdv_vendas')
            ->whereNull('canal')
            ->orWhere('canal', '')
            ->update(['canal' => 'HIPER_CAIXA']);

        Schema::table('pdv_vendas', function (Blueprint $table) {
            $table->dropUnique(self::LEGACY_UNIQUE);
            $table->unique(['store_pdv_id', 'canal', 'id_operacao'], self::CANONICAL_UNIQUE);
            $table->index('canal', self::CANAL_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('pdv_vendas', function (Blueprint $table) {
            $table->dropIndex(self::CANAL_INDEX);
            $table->dropUnique(self::CANONICAL_UNIQUE);
            $table->dropColumn('canal');
            $table->unique(['store_pdv_id', 'id_operacao'], self::LEGACY_UNIQUE);
        });
    }
};
