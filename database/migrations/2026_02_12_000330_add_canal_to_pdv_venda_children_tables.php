<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const ITEM_LEGACY_UNIQUE_LINE_ID = 'pdv_venda_itens_unique_line_id';
    private const ITEM_LEGACY_INDEX_LINE_ID = 'pdv_venda_itens_index_line_id';
    private const ITEM_LEGACY_UNIQUE_ROW_HASH = 'pdv_venda_itens_unique_row_hash';
    private const ITEM_LEGACY_UNIQUE_LINE = 'pdv_venda_itens_unique_line';

    private const PAYMENT_LEGACY_UNIQUE_LINE_ID = 'pdv_venda_pagamentos_unique_line_id';
    private const PAYMENT_LEGACY_INDEX_LINE_ID = 'pdv_venda_pagamentos_index_line_id';
    private const PAYMENT_LEGACY_UNIQUE_ROW_HASH = 'pdv_venda_pagamentos_unique_row_hash';
    private const PAYMENT_LEGACY_UNIQUE_LINE = 'pdv_venda_pagamentos_unique_line';

    private const ITEM_CANONICAL_UNIQUE_LINE_ID = 'pdv_venda_itens_unique_canal_line_id';
    private const ITEM_CANONICAL_UNIQUE_ROW_HASH = 'pdv_venda_itens_unique_canal_row_hash';
    private const ITEM_CANONICAL_UNIQUE_LINE = 'pdv_venda_itens_unique_canal_line';
    private const ITEM_CANAL_INDEX = 'pdv_venda_itens_idx_canal';

    private const PAYMENT_CANONICAL_UNIQUE_LINE_ID = 'pdv_venda_pagamentos_unique_canal_line_id';
    private const PAYMENT_CANONICAL_UNIQUE_ROW_HASH = 'pdv_venda_pagamentos_unique_canal_row_hash';
    private const PAYMENT_CANONICAL_UNIQUE_LINE = 'pdv_venda_pagamentos_unique_canal_line';
    private const PAYMENT_CANAL_INDEX = 'pdv_venda_pagamentos_idx_canal';

    public function up(): void
    {
        $this->addCanalColumn('pdv_venda_itens');
        $this->addCanalColumn('pdv_venda_pagamentos');

        $this->backfillCanalFromVendas('pdv_venda_itens');
        $this->backfillCanalFromVendas('pdv_venda_pagamentos');

        DB::table('pdv_venda_itens')
            ->whereNull('canal')
            ->orWhere('canal', '')
            ->update(['canal' => 'HIPER_CAIXA']);
        DB::table('pdv_venda_pagamentos')
            ->whereNull('canal')
            ->orWhere('canal', '')
            ->update(['canal' => 'HIPER_CAIXA']);

        $this->safeDropUnique('pdv_venda_itens', self::ITEM_LEGACY_UNIQUE_LINE_ID);
        $this->safeDropIndex('pdv_venda_itens', self::ITEM_LEGACY_INDEX_LINE_ID);
        $this->safeDropUnique('pdv_venda_itens', self::ITEM_LEGACY_UNIQUE_ROW_HASH);
        $this->safeDropUnique('pdv_venda_itens', self::ITEM_LEGACY_UNIQUE_LINE);

        $this->safeDropUnique('pdv_venda_pagamentos', self::PAYMENT_LEGACY_UNIQUE_LINE_ID);
        $this->safeDropIndex('pdv_venda_pagamentos', self::PAYMENT_LEGACY_INDEX_LINE_ID);
        $this->safeDropUnique('pdv_venda_pagamentos', self::PAYMENT_LEGACY_UNIQUE_ROW_HASH);
        $this->safeDropUnique('pdv_venda_pagamentos', self::PAYMENT_LEGACY_UNIQUE_LINE);

        Schema::table('pdv_venda_itens', function (Blueprint $table) {
            $table->unique(['store_pdv_id', 'canal', 'line_id'], self::ITEM_CANONICAL_UNIQUE_LINE_ID);
            $table->unique(['store_pdv_id', 'canal', 'id_operacao', 'row_hash'], self::ITEM_CANONICAL_UNIQUE_ROW_HASH);
            $table->unique(['store_pdv_id', 'canal', 'id_operacao', 'line_no'], self::ITEM_CANONICAL_UNIQUE_LINE);
            $table->index('canal', self::ITEM_CANAL_INDEX);
        });

        Schema::table('pdv_venda_pagamentos', function (Blueprint $table) {
            $table->unique(['store_pdv_id', 'canal', 'line_id'], self::PAYMENT_CANONICAL_UNIQUE_LINE_ID);
            $table->unique(['store_pdv_id', 'canal', 'id_operacao', 'row_hash'], self::PAYMENT_CANONICAL_UNIQUE_ROW_HASH);
            $table->unique(['store_pdv_id', 'canal', 'id_operacao', 'line_no'], self::PAYMENT_CANONICAL_UNIQUE_LINE);
            $table->index('canal', self::PAYMENT_CANAL_INDEX);
        });
    }

    public function down(): void
    {
        $this->safeDropIndex('pdv_venda_itens', self::ITEM_CANAL_INDEX);
        $this->safeDropUnique('pdv_venda_itens', self::ITEM_CANONICAL_UNIQUE_LINE_ID);
        $this->safeDropUnique('pdv_venda_itens', self::ITEM_CANONICAL_UNIQUE_ROW_HASH);
        $this->safeDropUnique('pdv_venda_itens', self::ITEM_CANONICAL_UNIQUE_LINE);

        $this->safeDropIndex('pdv_venda_pagamentos', self::PAYMENT_CANAL_INDEX);
        $this->safeDropUnique('pdv_venda_pagamentos', self::PAYMENT_CANONICAL_UNIQUE_LINE_ID);
        $this->safeDropUnique('pdv_venda_pagamentos', self::PAYMENT_CANONICAL_UNIQUE_ROW_HASH);
        $this->safeDropUnique('pdv_venda_pagamentos', self::PAYMENT_CANONICAL_UNIQUE_LINE);

        Schema::table('pdv_venda_itens', function (Blueprint $table) {
            $table->unique(['store_pdv_id', 'line_id'], self::ITEM_LEGACY_UNIQUE_LINE_ID);
            $table->index(['store_pdv_id', 'line_id'], self::ITEM_LEGACY_INDEX_LINE_ID);
            $table->unique(['store_pdv_id', 'id_operacao', 'row_hash'], self::ITEM_LEGACY_UNIQUE_ROW_HASH);
            $table->unique(['store_pdv_id', 'id_operacao', 'line_no'], self::ITEM_LEGACY_UNIQUE_LINE);
            $table->dropColumn('canal');
        });

        Schema::table('pdv_venda_pagamentos', function (Blueprint $table) {
            $table->unique(['store_pdv_id', 'line_id'], self::PAYMENT_LEGACY_UNIQUE_LINE_ID);
            $table->index(['store_pdv_id', 'line_id'], self::PAYMENT_LEGACY_INDEX_LINE_ID);
            $table->unique(['store_pdv_id', 'id_operacao', 'row_hash'], self::PAYMENT_LEGACY_UNIQUE_ROW_HASH);
            $table->unique(['store_pdv_id', 'id_operacao', 'line_no'], self::PAYMENT_LEGACY_UNIQUE_LINE);
            $table->dropColumn('canal');
        });
    }

    private function addCanalColumn(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (!Schema::hasColumn($tableName, 'canal')) {
                $table->string('canal', 20)->default('HIPER_CAIXA')->after('store_id');
            }
        });
    }

    private function backfillCanalFromVendas(string $tableName): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("
                UPDATE {$tableName} c
                LEFT JOIN pdv_vendas v
                    ON v.store_pdv_id = c.store_pdv_id
                   AND v.id_operacao = c.id_operacao
                SET c.canal = COALESCE(v.canal, 'HIPER_CAIXA')
            ");

            return;
        }

        DB::statement("
            UPDATE {$tableName}
            SET canal = COALESCE(
                (
                    SELECT v.canal
                    FROM pdv_vendas v
                    WHERE v.store_pdv_id = {$tableName}.store_pdv_id
                      AND v.id_operacao = {$tableName}.id_operacao
                    LIMIT 1
                ),
                'HIPER_CAIXA'
            )
        ");
    }

    private function safeDropUnique(string $tableName, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->dropUnique($indexName);
            });
        } catch (\Throwable) {
            // no-op: index may not exist in all environments
        }
    }

    private function safeDropIndex(string $tableName, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        } catch (\Throwable) {
            // no-op: index may not exist in all environments
        }
    }
};
