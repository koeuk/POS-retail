<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax is removed from the system. This shop does not charge it, so every
 * price is simply what the customer pays.
 *
 * Two columns go:
 *   - products.tax_rate   the per-product rate (already unused by the form)
 *   - orders.tax_amount   the tax recorded on each sale
 *
 * Dropping orders.tax_amount discards a historical figure. That is deliberate:
 * a column that can only ever be 0.00 from here on, sitting beside a total it
 * no longer explains, is worse than no column. The total itself is untouched.
 *
 * The default_tax_rate setting is deleted rather than left as a dead key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
        });

        DB::table('settings')->where('key', 'default_tax_rate')->delete();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->nullable()->after('sell_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('tax_amount', 12, 2)->default(0)->after('discount_amount');
        });
    }
};
