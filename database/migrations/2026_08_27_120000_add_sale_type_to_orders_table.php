<?php

use App\Enums\SaleType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why each sale happened — see App\Enums\SaleType.
 *
 * Every existing row is a plain customer sale, which is what the default
 * says, so nothing historical changes meaning. Indexed because the reporter
 * filters on it for every revenue figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('sale_type', SaleType::values())
                ->default(SaleType::Customer->value)
                ->after('customer_id');

            $table->index('sale_type');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['sale_type']);
            $table->dropColumn('sale_type');
        });
    }
};
