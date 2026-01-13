<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_instances', function (Blueprint $table) {
            $table->id();

            // Scope: global (both null), store, or user
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // Provider (evolution, z-api, etc.)
            $table->string('provider', 50)->default('evolution');

            // Instance identification
            $table->string('name', 60);
            $table->string('phone_e164', 20)->nullable();
            $table->string('base_url', 255);

            // Flags
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();

            // Secrets (encrypted in model)
            $table->text('api_key')->nullable();
            $table->string('api_key_last4', 4)->nullable();
            $table->string('api_key_fingerprint', 16)->nullable();
            $table->text('token')->nullable();
            $table->string('token_last4', 4)->nullable();
            $table->string('token_fingerprint', 16)->nullable();

            // Connection status
            $table->enum('status', ['unknown', 'connected', 'disconnected', 'connecting'])
                ->default('unknown');
            $table->json('last_state')->nullable();
            $table->timestamp('last_state_checked_at')->nullable();

            // Webhook configuration
            $table->string('webhook_secret', 255)->nullable();
            $table->string('webhook_url', 255)->nullable();
            $table->json('webhook_events')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            // Unique constraint: provider + base_url + name (including soft deleted)
            $table->unique(['provider', 'base_url', 'name', 'deleted_at'], 'whatsapp_instances_unique');

            // Indexes for default lookup per scope
            $table->index(['store_id', 'is_default']);
            $table->index(['user_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_instances');
    }
};
