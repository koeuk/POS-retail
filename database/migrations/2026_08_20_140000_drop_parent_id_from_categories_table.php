<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categories are a single flat list — a category is just a category. The
 * two-level tree was more structure than the POS grid ever needed, so the
 * self-reference goes away and every former child becomes a category in
 * its own right.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'parent_id')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            // Drops the foreign key and the column; MySQL removes the
            // accompanying index along with the column.
            $table->dropConstrainedForeignId('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->index('parent_id');
        });
    }
};
