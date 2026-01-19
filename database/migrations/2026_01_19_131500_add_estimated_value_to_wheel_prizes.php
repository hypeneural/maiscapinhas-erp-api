<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona campo estimated_value para cálculo de ROI em analytics.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('wheel_prizes', function (Blueprint $table) {
            // Valor estimado do prêmio em reais (para cálculo de ROI)
            $table->decimal('estimated_value', 10, 2)
                ->nullable()
                ->after('code_prefix')
                ->comment('Valor estimado do prêmio em R$ para métricas de ROI');
        });

        // Popular valores estimados padrão para prêmios existentes
        $defaults = [
            'product' => 15.00,  // Produtos físicos
            'coupon' => 10.00,   // Cupons de desconto
            'nothing' => 0,      // Não ganhou
            'try_again' => 0,    // Tente novamente
        ];

        foreach ($defaults as $type => $value) {
            \DB::table('wheel_prizes')
                ->where('type', $type)
                ->update(['estimated_value' => $value]);
        }
    }

    public function down(): void
    {
        Schema::table('wheel_prizes', function (Blueprint $table) {
            $table->dropColumn('estimated_value');
        });
    }
};
