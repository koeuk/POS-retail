<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many units make a case — for counting only.
 *
 * A pack size (a child product) is something the shop sells. A case size is
 * how the goods arrive and how the shelf is counted: "18 cases and 22 loose"
 * rather than 1,462. Plenty of shops never sell a whole case, so this lives on
 * the product itself and needs no price. Null means the shop counts singles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('case_size')->nullable()->after('units_per_pack');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('case_size');
        });
    }
};
