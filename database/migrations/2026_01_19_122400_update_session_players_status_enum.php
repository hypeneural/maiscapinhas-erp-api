<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Atualiza o enum de status em wheel_session_players para alinhar com PlayerStatus enum.
 * 
 * DB antigo: 'pending', 'verifying', 'verified', 'playing', 'spinning', 'completed', 'disconnected'
 * PHP enum: 'pending', 'verifying', 'verified', 'spinning', 'won', 'lost', 'left', 'timeout'
 */
return new class extends Migration {
    public function up(): void
    {
        // MySQL requires ALTER TABLE MODIFY COLUMN for enum changes
        // First, map old values to new values
        DB::statement("UPDATE wheel_session_players SET status = 'verified' WHERE status = 'playing'");
        DB::statement("UPDATE wheel_session_players SET status = 'won' WHERE status = 'completed'");
        DB::statement("UPDATE wheel_session_players SET status = 'timeout' WHERE status = 'disconnected'");

        // Then alter the enum to the new values
        DB::statement("ALTER TABLE wheel_session_players MODIFY COLUMN status ENUM('pending', 'verifying', 'verified', 'spinning', 'won', 'lost', 'left', 'timeout', 'completed') DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Reverter para o enum original
        DB::statement("UPDATE wheel_session_players SET status = 'completed' WHERE status = 'won'");
        DB::statement("UPDATE wheel_session_players SET status = 'completed' WHERE status = 'lost'");
        DB::statement("UPDATE wheel_session_players SET status = 'disconnected' WHERE status IN ('left', 'timeout')");

        DB::statement("ALTER TABLE wheel_session_players MODIFY COLUMN status ENUM('pending', 'verifying', 'verified', 'playing', 'spinning', 'completed', 'disconnected') DEFAULT 'pending'");
    }
};
