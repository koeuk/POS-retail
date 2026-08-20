<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Cashier = 'cashier';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Manager => 'Manager',
            self::Cashier => 'Cashier',
        };
    }

    /**
     * A cashier is bound to exactly one store — /pos cannot resolve which
     * stock rows to read without it. Admins and managers are store-agnostic.
     */
    public function requiresStore(): bool
    {
        return $this === self::Cashier;
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
