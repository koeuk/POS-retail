<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Which vendor's data the current request may see.
 *
 * Multi-vendor in one sentence: every non-admin is pinned to their own
 * vendor (NULL = the admin's own shop), and an admin sees everything unless
 * they pick one vendor to look at through the "Viewing" switcher.
 *
 * Models opt in with the BelongsToVendor / ScopedByStore traits, which apply
 * this as a global scope — so Eloquent queries, route model binding and
 * relations are all fenced without each controller remembering to. Raw
 * query-builder reads and `exists` validation rules do NOT pass through a
 * global scope; they call constrain() / exists() / existsInStore() here.
 */
final class Tenant
{
    /** Session key holding an admin's "Viewing" choice. */
    public const SESSION_KEY = 'vendor_context';

    /** The admin's-own-shop choice in the switcher (vendor_id IS NULL). */
    public const SHOP = 'shop';

    private static bool $bypass = false;

    /**
     * The vendor the request is fenced to: an id, `null` for the admin shop,
     * or `false` when nothing is fenced (an admin viewing everything, a guest,
     * the console, or code inside unscoped()).
     */
    public static function current(): int|null|false
    {
        if (self::$bypass) {
            return false;
        }

        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            $choice = request()->hasSession() ? request()->session()->get(self::SESSION_KEY) : null;

            return match (true) {
                $choice === null => false,
                $choice === self::SHOP => null,
                default => (int) $choice,
            };
        }

        return $user->vendor_id;
    }

    public static function scoped(): bool
    {
        return self::current() !== false;
    }

    /** Run `$callback` with every fence down — for the admin's cross-vendor views. */
    public static function unscoped(callable $callback): mixed
    {
        $previous = self::$bypass;
        self::$bypass = true;

        try {
            return $callback();
        } finally {
            self::$bypass = $previous;
        }
    }

    /** Fence any query on a vendor_id column. */
    public static function constrain(Builder $query, string $column): void
    {
        $tenant = self::current();

        if ($tenant === false) {
            return;
        }

        $tenant === null ? $query->whereNull($column) : $query->where($column, $tenant);
    }

    /** Fence any query on a store_id column to the tenant's stores. */
    public static function constrainStore(Builder $query, string $column): void
    {
        if (self::scoped()) {
            $query->whereIn($column, Store::query()->select('id'));
        }
    }

    /** `exists` for a table with its own vendor_id — no reaching into another vendor's rows by id. */
    public static function exists(string $table): Exists
    {
        return Rule::exists($table, 'id')->where(fn (Builder $q) => self::constrain($q, "{$table}.vendor_id"));
    }

    /** `exists` for a table owned through its store (registers, stocks). */
    public static function existsInStore(string $table): Exists
    {
        return Rule::exists($table, 'id')->where(fn (Builder $q) => self::constrainStore($q, "{$table}.store_id"));
    }
}
