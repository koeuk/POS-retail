<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * users already exists (Laravel default). This adds the POS columns.
     *
     * store_id is nullable at the DB level because admins and managers have
     * no home store, but it is enforced non-null for cashiers in validation —
     * /pos cannot resolve stock rows without one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', Role::values())->default(Role::Cashier->value)->after('password');
            $table->foreignId('store_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('store_id');

            $table->index(['role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'is_active']);
            $table->dropConstrainedForeignId('store_id');
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
