<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('permission_id');
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete()
                ->comment('NULL = override global, ID = override apenas nesta loja');
            $table->boolean('granted')->default(true)
                ->comment('true = libera permissão, false = nega permissão');
            $table->timestamp('expires_at')->nullable()
                ->comment('Permissão temporária');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable()
                ->comment('Motivo do override (para auditoria)');
            $table->timestamps();

            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->unique(['user_id', 'permission_id', 'store_id'], 'user_perm_override_unique');
            $table->index('store_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
