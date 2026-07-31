<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            // ProductsSync matches on `sku`; the unique index is what keeps
            // `sync:doctor` quiet and makes repeated runs upsert rather than
            // duplicate.
            $table->string('sku')->unique();
            $table->string('name');
            $table->unsignedInteger('price');
            $table->string('category')->default('general');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
