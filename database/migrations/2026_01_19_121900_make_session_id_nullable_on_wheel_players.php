<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Torna session_id nullable em wheel_players.
 * 
 * Motivo: wheel_players agora representa uma PESSOA, não uma participação em sessão.
 * A relação com sessões é feita via wheel_session_players (pivot).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('wheel_players', function (Blueprint $table) {
            // Verificar se a coluna session_id existe
            if (Schema::hasColumn('wheel_players', 'session_id')) {
                // Remover a foreign key constraint se existir
                $table->dropForeign(['session_id']);

                // Tornar session_id nullable (coluna legada, pode ser removida futuramente)
                $table->unsignedBigInteger('session_id')->nullable()->change();
            }

            // Remover colunas legadas de fila que agora estão em wheel_session_players
            if (Schema::hasColumn('wheel_players', 'queue_position')) {
                $table->dropColumn('queue_position');
            }
            if (Schema::hasColumn('wheel_players', 'access_token_hash')) {
                $table->dropColumn('access_token_hash');
            }
            if (Schema::hasColumn('wheel_players', 'status') && Schema::hasColumn('wheel_session_players', 'status')) {
                // Manter status para compatibilidade
            }
        });
    }

    public function down(): void
    {
        Schema::table('wheel_players', function (Blueprint $table) {
            // Não podemos reverter facilmente sem dados
            // A migração down é um no-op para evitar perda de dados
        });
    }
};
