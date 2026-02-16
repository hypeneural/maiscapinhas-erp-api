<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hiper_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('method', 10); // GET or POST
            $table->string('path');
            $table->json('headers')->nullable();
            $table->json('query_template')->nullable();
            $table->json('body_template')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiper_endpoints');
    }
};
