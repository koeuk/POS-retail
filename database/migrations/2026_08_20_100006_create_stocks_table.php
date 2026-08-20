<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            // Signed on purpose. An offline sale synced later may legitimately
            // push this negative; we record the truth rather than clamp it.
            $table->integer('qty')->default(0);

            $table->integer('low_stock_threshold')->nullable();
            $table->timestamps();

            // Without this, duplicate rows appear and decrement logic silently
            // splits across them.
            $table->unique(['product_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
