<?php

use App\Enums\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Roles become superadmin / admin / vendor / cashier.
 *
 * - Every existing admin becomes a superadmin: they owned the shop, and the
 *   superadmin is the only role that still manages admins and settings.
 * - Every manager becomes a cashier carrying its old manager access as
 *   per-user overrides, so nobody gains or loses a screen in the move.
 */
return new class extends Migration
{
    private const OLD = ['admin', 'manager', 'cashier', 'vendor'];

    private const NEW = ['superadmin', 'admin', 'cashier', 'vendor'];

    public function up(): void
    {
        $this->setEnum([...self::OLD, 'superadmin']);

        DB::table('users')->where('role', 'admin')->update(['role' => 'superadmin']);

        // What a manager held by default: everything but Staff, the audit
        // trail and the vendor list. Its own overrides still win on top.
        $managerDefaults = collect(Permission::values())
            ->mapWithKeys(fn (string $key) => [$key => ! in_array($key, ['users', 'activity', 'vendors'], true)])
            ->all();

        DB::table('users')->where('role', 'manager')->orderBy('id')->each(function (object $user) use ($managerDefaults) {
            $overrides = json_decode($user->permissions ?? 'null', true) ?? [];

            DB::table('users')->where('id', $user->id)->update([
                'role' => 'cashier',
                'permissions' => json_encode(array_replace($managerDefaults, $overrides)),
            ]);
        });

        $this->setEnum(self::NEW);
    }

    public function down(): void
    {
        $this->setEnum([...self::NEW, 'manager']);

        // Converted managers cannot be told apart from cashiers any more; they
        // stay cashiers with the same overrides, which is the same access.
        DB::table('users')->whereIn('role', ['superadmin'])->update(['role' => 'admin']);

        $this->setEnum(self::OLD);
    }

    /** @param string[] $values */
    private function setEnum(array $values): void
    {
        Schema::table('users', function (Blueprint $table) use ($values) {
            $table->enum('role', $values)->default('cashier')->change();
        });
    }
};
