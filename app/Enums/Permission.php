<?php

namespace App\Enums;

/**
 * One key per feature area — the unit a user can be granted or denied.
 *
 * A user's role sets the defaults; per-user overrides live in the
 * users.permissions JSON column. Admins bypass the whole table: an admin
 * always holds every permission, so nobody can lock the shop out of its
 * own back office.
 */
enum Permission: string
{
    case Pos = 'pos';
    case Orders = 'orders';
    case Debts = 'debts';
    case Consumption = 'consumption';
    case Reports = 'reports';
    case Products = 'products';
    case Categories = 'categories';
    case Inventory = 'inventory';
    case Customers = 'customers';
    case Users = 'users';
    case Stores = 'stores';

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'Point of Sale',
            self::Orders => 'Order history',
            self::Debts => 'In Debt',
            self::Consumption => 'Myself (consumption)',
            self::Reports => 'Reports',
            self::Products => 'Products',
            self::Categories => 'Categories',
            self::Inventory => 'Inventory',
            self::Customers => 'Customers',
            self::Users => 'Staff',
            self::Stores => 'Stores & registers',
        };
    }

    /** Section header the settings dialog groups the switch under. */
    public function group(): string
    {
        return match ($this) {
            self::Pos, self::Orders, self::Debts, self::Consumption, self::Reports => 'Selling',
            self::Products, self::Categories, self::Inventory => 'Catalogue',
            self::Customers, self::Users, self::Stores => 'People & stores',
        };
    }

    /** What this permission is worth before any per-user override. */
    public function defaultFor(Role $role): bool
    {
        return match ($role) {
            Role::Admin => true,
            Role::Manager => $this !== self::Users,
            Role::Cashier => $this === self::Pos,
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
