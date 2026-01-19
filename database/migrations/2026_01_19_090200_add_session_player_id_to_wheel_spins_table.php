<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atualiza wheel_spins para usar session_player_id ao invés de player_id.
 * Isso conecta o spin à participação específica, não apenas à pessoa.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('wheel_spins', function (Blueprint $table) {
            // Adicionar nova FK para session_player
            $table->foreignId('session_player_id')
                ->nullable()
                ->after('player_id')
                ->constrained('wheel_session_players')
                ->nullOnDelete();

            // Manter player_id como nullable para compatibilidade
            // Podemos remover depois de migrar os dados
        });
    }

    public function down(): void
    {
        Schema::table('wheel_spins', function (Blueprint $table) {
            $table->dropForeign(['session_player_id']);
            $table->dropColumn('session_player_id');
        });
    }
};
