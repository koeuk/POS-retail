<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
            return;
        }

        /*
         * Older installations called the unrestricted owner role
         * "superadmin". The current authorization model calls that same role
         * "admin", so normalize the stored value before Eloquent casts it to
         * the Role enum.
         *
         * MySQL ENUM columns must temporarily accept both spellings or the
         * data update can be rejected on an old schema.
         */
        if (DB::getDriverName() === 'mysql') {
            $this->setMysqlRoleValues([...Role::values(), 'superadmin']);
        }

        DB::table('users')
            ->where('role', 'superadmin')
            ->update(['role' => Role::Admin->value]);

        if (DB::getDriverName() === 'mysql') {
            $this->setMysqlRoleValues(Role::values());
        }
    }

    public function down(): void
    {
        // The legacy value is intentionally not restored.
    }

    /** @param array<int, string> $roles */
    private function setMysqlRoleValues(array $roles): void
    {
        $values = collect($roles)
            ->map(fn (string $role): string => DB::getPdo()->quote($role))
            ->implode(', ');

        DB::statement(
            "ALTER TABLE `users` MODIFY `role` ENUM({$values}) NOT NULL DEFAULT 'cashier'"
        );
    }
};
