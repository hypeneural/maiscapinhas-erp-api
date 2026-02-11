<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdv_venda_itens', function (Blueprint $table) {
            if (!Schema::hasColumn('pdv_venda_itens', 'line_id')) {
                $table->unsignedBigInteger('line_id')->nullable()->after('id_operacao');
            }
        });

        Schema::table('pdv_venda_pagamentos', function (Blueprint $table) {
            if (!Schema::hasColumn('pdv_venda_pagamentos', 'line_id')) {
                $table->unsignedBigInteger('line_id')->nullable()->after('id_operacao');
            }
        });

        Schema::table('pdv_venda_itens', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_venda_itens', 'line_id')) {
                $table->unique(['store_pdv_id', 'line_id'], 'pdv_venda_itens_unique_line_id');
                $table->index(['store_pdv_id', 'line_id'], 'pdv_venda_itens_index_line_id');
            }
        });

        Schema::table('pdv_venda_pagamentos', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_venda_pagamentos', 'line_id')) {
                $table->unique(['store_pdv_id', 'line_id'], 'pdv_venda_pagamentos_unique_line_id');
                $table->index(['store_pdv_id', 'line_id'], 'pdv_venda_pagamentos_index_line_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdv_venda_itens', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_venda_itens', 'line_id')) {
                $table->dropIndex('pdv_venda_itens_index_line_id');
                $table->dropUnique('pdv_venda_itens_unique_line_id');
                $table->dropColumn('line_id');
            }
        });

        Schema::table('pdv_venda_pagamentos', function (Blueprint $table) {
            if (Schema::hasColumn('pdv_venda_pagamentos', 'line_id')) {
                $table->dropIndex('pdv_venda_pagamentos_index_line_id');
                $table->dropUnique('pdv_venda_pagamentos_unique_line_id');
                $table->dropColumn('line_id');
            }
        });
    }
};
