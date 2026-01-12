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
        Schema::create('capas_personalizadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // vendedor solicitante
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('customer_device_id')->nullable()->constrained('customer_devices')->nullOnDelete();

            $table->string('selected_product');
            $table->string('product_reference')->nullable();
            $table->text('obs')->nullable();
            $table->string('photo_path')->nullable();

            $table->unsignedInteger('qty')->default(1);
            $table->decimal('price', 10, 2)->nullable();

            // Payment
            $table->boolean('payed')->default(false);
            $table->date('payday')->nullable();
            $table->foreignId('received_by_id')->nullable()->constrained('users')->nullOnDelete();

            // Production
            $table->date('sended_to_production_at')->nullable();

            // Status: 1=encomenda_solicitada, 2=produto_indisponivel, 3=disponivel_loja, 4=venda_realizada, 5=cancelada, 6=enviado_producao
            $table->tinyInteger('status')->default(1);

            // Audit
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['store_id', 'user_id', 'status', 'created_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['payed', 'payday', 'received_by_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capas_personalizadas');
    }
};
