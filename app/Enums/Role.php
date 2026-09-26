<?php

namespace App\Enums;

enum Role: string
{
    /** Owns the platform: the only role that manages admins, permissions and settings. */
    case Superadmin = 'superadmin';
    case Admin = 'admin';
    case Cashier = 'cashier';
    case Vendor = 'vendor';

    public function label(): string
    {
        return match ($this) {
            self::Superadmin => 'Super administrator',
            self::Admin => 'Administrator',
            self::Cashier => 'Cashier',
            self::Vendor => 'Vendor',
        };
    }

    /**
     * A cashier is bound to exactly one store — /pos cannot resolve which
     * stock rows to read without it. Admins and vendors are store-agnostic.
     */
    public function requiresStore(): bool
    {
        return $this === self::Cashier;
    }

    /**
     * A vendor account speaks for one supplier — the account is meaningless
     * without knowing which, so the vendor is required on save.
     */
    public function requiresVendor(): bool
    {
        return $this === self::Vendor;
    }

    public function canAccessAdmin(): bool
    {
        return $this !== self::Cashier;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
