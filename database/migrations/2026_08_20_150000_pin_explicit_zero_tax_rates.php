<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The meaning of a null tax_rate changes here.
 *
 * Before: null meant 0% — an explicit "this product is not taxed".
 * After:  null means "inherit the default_tax_rate setting", because tax is
 *         no longer edited per product.
 *
 * Any product already carrying null meant the OLD thing, so pin it to an
 * explicit 0.00 first. Without this the zero-rated lines (bottled water in
 * the seed data) would silently start charging the default rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->whereNull('tax_rate')->update(['tax_rate' => 0.00]);
    }

    public function down(): void
    {
        // Not reversible in a meaningful way: once pinned, a 0.00 that was
        // originally null is indistinguishable from one typed by hand.
    }
};
