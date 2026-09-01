<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The rest of the POS columns live on the users table itself; only
     * store_id has to wait, because its foreign key needs the stores table,
     * which is created after users.
     *
     * It is nullable at the DB level because admins and managers have no home
     * store, but it is enforced non-null for cashiers in validation — /pos
     * cannot resolve stock rows without one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('permissions')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
