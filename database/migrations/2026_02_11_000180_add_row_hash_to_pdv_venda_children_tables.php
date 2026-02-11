<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_venda_itens', function (Blueprint $table) {
            $table->char('row_hash', 64)->nullable()->after('line_no');
        });

        Schema::table('pdv_venda_pagamentos', function (Blueprint $table) {
            $table->char('row_hash', 64)->nullable()->after('line_no');
        });

        $this->backfillItemRowHash();
        $this->backfillPaymentRowHash();

        Schema::table('pdv_venda_itens', function (Blueprint $table) {
            $table->unique(['store_pdv_id', 'id_operacao', 'row_hash'], 'pdv_venda_itens_unique_row_hash');
        });

        Schema::table('pdv_venda_pagamentos', function (Blueprint $table) {
            $table->unique(['store_pdv_id', 'id_operacao', 'row_hash'], 'pdv_venda_pagamentos_unique_row_hash');
        });
    }

    public function down(): void
    {
        Schema::table('pdv_venda_itens', function (Blueprint $table) {
            $table->dropUnique('pdv_venda_itens_unique_row_hash');
            $table->dropColumn('row_hash');
        });

        Schema::table('pdv_venda_pagamentos', function (Blueprint $table) {
            $table->dropUnique('pdv_venda_pagamentos_unique_row_hash');
            $table->dropColumn('row_hash');
        });
    }

    private function backfillItemRowHash(): void
    {
        DB::table('pdv_venda_itens')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $lineNo = (int) $row->line_no;
                    $hash = hash('sha256', implode('|', [
                        'pdv',
                        'item',
                        (int) $row->store_pdv_id,
                        (int) $row->id_operacao,
                        'line:' . $lineNo,
                    ]));

                    DB::table('pdv_venda_itens')
                        ->where('id', $row->id)
                        ->update(['row_hash' => $hash]);
                }
            });
    }

    private function backfillPaymentRowHash(): void
    {
        DB::table('pdv_venda_pagamentos')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $lineNo = (int) $row->line_no;
                    $hash = hash('sha256', implode('|', [
                        'pdv',
                        'payment',
                        (int) $row->store_pdv_id,
                        (int) $row->id_operacao,
                        'line:' . $lineNo,
                    ]));

                    DB::table('pdv_venda_pagamentos')
                        ->where('id', $row->id)
                        ->update(['row_hash' => $hash]);
                }
            });
    }
};
