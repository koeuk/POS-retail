<?php

use App\Support\Currency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money stops being dollars-with-a-rate and becomes the shop's own currency.
 *
 * Every amount was stored in USD and multiplied by `riel_per_usd` on display.
 * That cannot express riel: one US cent is 40៛, so a 500៛ price was kept as
 * 13 cents and shown back as 520៛. The error was in the storage, so no amount
 * of display work could fix it.
 *
 * This converts what is already there — once — and records on each order which
 * currency its figures are in, so an order rung up before the change still
 * means what it meant then.
 */
return new class extends Migration
{
    /** Columns holding an amount, by table. */
    private const MONEY = [
        'products' => ['cost_price', 'sell_price'],
        'orders' => ['subtotal', 'discount_amount', 'total', 'paid_amount', 'change_amount'],
        'order_items' => ['unit_price', 'discount', 'subtotal'],
        'payments' => ['amount'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'currency')) {
            Schema::table('orders', function (Blueprint $table) {
                // Snapshot. The shop may switch later; a past sale must not
                // silently change what it was worth.
                $table->char('currency', 3)->default(Currency::USD)->after('total');
            });
        }

        $currency = Currency::current();

        // Everything already on file was rung up in dollars.
        DB::table('orders')->update(['currency' => Currency::USD]);

        if ($currency->code === Currency::USD) {
            return;
        }

        $rate = $currency->rielPerUsd;
        $decimals = $currency->decimals;

        foreach (self::MONEY as $table => $columns) {
            foreach ($columns as $column) {
                // ROUND in SQL rather than reading rows into PHP: a busy shop's
                // order_items table is the one place this could be large.
                DB::table($table)->update([
                    $column => DB::raw("ROUND({$column} * {$rate}, {$decimals})"),
                ]);
            }
        }

        // Those orders are now expressed in the new currency.
        DB::table('orders')->update(['currency' => $currency->code]);
    }

    public function down(): void
    {
        $currency = Currency::current();

        if ($currency->code !== Currency::USD) {
            $rate = $currency->rielPerUsd;

            foreach (self::MONEY as $table => $columns) {
                foreach ($columns as $column) {
                    DB::table($table)->update([
                        $column => DB::raw("ROUND({$column} / {$rate}, 2)"),
                    ]);
                }
            }
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
