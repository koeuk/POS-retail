<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * Per-user permission overrides, {"reports": true, ...}. NULL
             * means "no overrides" — the role's defaults apply untouched, so
             * existing accounts behave exactly as before this column existed.
             */
            $table->json('permissions')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
