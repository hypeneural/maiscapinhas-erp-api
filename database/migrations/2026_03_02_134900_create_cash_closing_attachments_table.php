<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cash_closing_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_closing_id')->constrained('cash_closings')->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->string('file_type', 50);
            $table->unsignedInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('cash_closing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closing_attachments');
    }
};
