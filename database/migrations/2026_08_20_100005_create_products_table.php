<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();

            /*
             * Pack sizes. The same beer is sold by the case, the twelve, the
             * six and the single — one product counted four ways, not four
             * products. A pack is an ordinary row that draws its stock from
             * `parent_product_id`, and `units_per_pack` says how much of it
             * one sale consumes. Restricted on delete so a base product
             * cannot vanish while its packs are still sellable.
             */
            $table->foreignId('parent_product_id')
                ->nullable()
                ->constrained('products')
                ->restrictOnDelete();

            $table->string('name');
            $table->string('sku')->unique();
            $table->string('barcode')->nullable()->unique();
            $table->text('description')->nullable();
            $table->decimal('cost_price', 12, 2)->default(0);
            // The price the customer pays. There is no tax in this shop.
            $table->decimal('sell_price', 12, 2)->default(0);

            $table->string('image')->nullable();
            $table->string('unit')->default('pcs');
            $table->unsignedInteger('units_per_pack')->default(1);
            $table->boolean('track_stock')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // /pos/data/products filters on is_active; grid searches by name.
            $table->index(['is_active', 'category_id']);
            $table->index('name');
            $table->index('parent_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
