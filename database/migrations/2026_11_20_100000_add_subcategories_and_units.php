<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->string('symbol', 20)->unique();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
        });

        // Preserve every existing unit and quantity, including custom labels.
        $symbols = DB::table('products')->distinct()->pluck('unit')
            ->merge(['pcs', 'bottle', 'can', 'pack', 'carton', 'g', 'ml'])->unique();
        foreach ($symbols as $symbol) {
            // Use the database collation: existing 'PCS' and default 'pcs' may be equal.
            $id = DB::table('units')->where('symbol', $symbol)->value('id');
            $id ??= DB::table('units')->insertGetId([
                'uuid' => (string) Str::uuid(), 'name' => $symbol, 'symbol' => $symbol,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('products')->where('unit', $symbol)->update(['unit_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropConstrainedForeignId('unit_id'));
        Schema::dropIfExists('units');
        Schema::table('categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('parent_id'));
    }
};
