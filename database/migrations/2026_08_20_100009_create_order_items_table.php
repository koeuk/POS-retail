<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Plain column, deliberately NOT a foreign key: product_variants is
            // deferred to v2, so the constraint has nothing to point at yet.
            $table->unsignedBigInteger('product_variant_id')->nullable();

            // Snapshots. Never join back to products for historical accuracy —
            // names get edited and prices change.
            $table->string('product_name');
            $table->decimal('unit_price', 12, 2);

            $table->integer('qty');
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();

            // Drives the sales-by-product report.
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
