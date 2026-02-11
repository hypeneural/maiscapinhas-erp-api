<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_sync_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdv_sync_id')->unique()->constrained('pdv_syncs')->cascadeOnDelete();
            $table->longText('payload');
            $table->string('compression', 20)->default('none');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_sync_payloads');
    }
};
