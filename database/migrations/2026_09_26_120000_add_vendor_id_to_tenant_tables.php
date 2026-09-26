<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Multi-vendor: each vendor keeps its own stores, categories and customers.
 * NULL is the admin's own shop. Everything else is reached through these —
 * orders, registers, stock and movements through their store; products
 * already carry vendor_id. See App\Support\Tenant.
 */
return new class extends Migration
{
    private const TABLES = ['stores', 'categories', 'customers'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                // Restrict: a vendor cannot vanish while it still owns rows.
                $t->foreignId('vendor_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('vendor_id');
            });
        }
    }
};
