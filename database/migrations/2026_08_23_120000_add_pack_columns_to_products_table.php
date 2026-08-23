<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pack sizes.
 *
 * A shop sells the same beer as a case, a half case, a six-pack and a single
 * can. Those are not four products — they are one product counted four ways,
 * and modelling them separately gives four independent stock figures that
 * immediately disagree with the shelf.
 *
 * So a pack is an ordinary product row that draws its stock from a parent:
 * everything already built on `products` — the POS grid, barcode scanning,
 * order items, reports, receipts — keeps working untouched, and only the
 * stock decrement has to know the difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Restrict rather than cascade: deleting a base product while its
            // packs are still sellable would leave rows with nowhere to take
            // stock from. The packs must be dealt with first, deliberately.
            $table->foreignId('parent_product_id')
                ->nullable()
                ->after('category_id')
                ->constrained('products')
                ->restrictOnDelete();

            // How many base units one of these contains. 1 for a base product
            // and for a pack that happens to be a single.
            $table->unsignedInteger('units_per_pack')->default(1)->after('unit');

            $table->index('parent_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_product_id');
            $table->dropColumn('units_per_pack');
        });
    }
};
