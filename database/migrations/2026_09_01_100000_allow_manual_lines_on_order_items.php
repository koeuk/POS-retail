<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A debt can now be typed straight onto a customer — "៛10,000, rice and oil" —
 * without ringing products through the till. Such a line has no product row
 * behind it; product_name (already the snapshot every report groups on)
 * carries what it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Refuses if manual lines exist — they have no product to point at,
        // and inventing one would be worse than failing loudly.
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable(false)->change();
        });
    }
};
