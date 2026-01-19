<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela: wheel_phone_challenges
 * 
 * Desafios de verificação de telefone via WhatsApp/SMS.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wheel_phone_challenges', function (Blueprint $table) {
            $table->id();

            // Jogador que está verificando
            $table->foreignId('player_id')
                ->constrained('wheel_players')
                ->cascadeOnDelete();

            // Telefone sendo verificado
            $table->string('phone', 20);

            // Código de verificação (6 dígitos)
            $table->string('code', 10);

            // Método de envio
            $table->enum('method', ['whatsapp', 'sms'])->default('whatsapp');

            // Status
            $table->enum('status', ['pending', 'sent', 'verified', 'failed', 'expired'])
                ->default('pending');

            // Tentativas de verificação
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);

            // Quando expira
            $table->timestamp('expires_at');

            // Quando foi enviado
            $table->timestamp('sent_at')->nullable();

            // Quando foi verificado
            $table->timestamp('verified_at')->nullable();

            // Response da API de envio (Evolution, etc.)
            $table->json('send_response')->nullable();

            $table->timestamps();

            // Índices
            $table->index(['player_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_phone_challenges');
    }
};
