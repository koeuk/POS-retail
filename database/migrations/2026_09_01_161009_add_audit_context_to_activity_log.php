<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit context Spatie's table does not carry.
 *
 * These live in their own migration so the package's published migration can
 * be replaced wholesale on the next major upgrade without losing them. The
 * columns are indexed because the Activity screen filters on all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            // Which shop the action happened in. Null for admins acting
            // outside any store, and for console/seeder activity.
            $table->foreignId('store_id')->nullable()->after('causer_id')
                ->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable()->after('properties');
            $table->string('user_agent')->nullable()->after('ip_address');

            $table->index('created_at');
            $table->index(['log_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropIndex(['log_name', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['store_id', 'ip_address', 'user_agent']);
        });
    }
};
