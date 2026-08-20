<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\Register;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::create([
            'name' => 'Main Store',
            'address' => '123 Central Market Street',
            'phone' => '+855 12 345 678',
        ]);

        Register::create([
            'store_id' => $store->id,
            'name' => 'Register 1',
            'is_active' => true,
        ]);

        $this->seedUsers($store);
        $categories = $this->seedCategories();
        $this->seedProducts($store, $categories);
        $this->seedSettings($store);
    }

    private function seedUsers(Store $store): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@pos.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'role' => Role::Admin,
            'store_id' => null,
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Manager',
            'email' => 'manager@pos.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'role' => Role::Manager,
            'store_id' => $store->id,
            'is_active' => true,
        ]);

        // A cashier must be store-bound — /pos cannot resolve stock without it.
        User::create([
            'name' => 'Cashier',
            'email' => 'cashier@pos.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'role' => Role::Cashier,
            'store_id' => $store->id,
            'is_active' => true,
        ]);
    }

    /** @return array<string, Category> */
    private function seedCategories(): array
    {
        $tree = [
            'Beverages' => ['Soft Drinks', 'Water', 'Coffee & Tea'],
            'Snacks' => ['Chips', 'Biscuits'],
            'Household' => ['Cleaning', 'Paper Goods'],
            'Personal Care' => [],
        ];

        $flat = [];

        foreach ($tree as $parentName => $childNames) {
            $parent = Category::create(['name' => $parentName, 'parent_id' => null]);
            $flat[$parentName] = $parent;

            foreach ($childNames as $childName) {
                $flat[$childName] = Category::create([
                    'name' => $childName,
                    'parent_id' => $parent->id,
                ]);
            }
        }

        return $flat;
    }

    /** @param array<string, Category> $categories */
    private function seedProducts(Store $store, array $categories): void
    {
        // [name, category, cost, sell, tax_rate, unit]
        // tax_rate null means 0% — deliberately mixed so the per-line
        // tax-exclusive calculation gets exercised by real data.
        $products = [
            ['Cola 330ml', 'Soft Drinks', 0.35, 0.75, 10.00, 'can'],
            ['Cola 1.5L', 'Soft Drinks', 0.90, 1.80, 10.00, 'btl'],
            ['Orange Soda 330ml', 'Soft Drinks', 0.33, 0.70, 10.00, 'can'],
            ['Lemon Soda 330ml', 'Soft Drinks', 0.33, 0.70, 10.00, 'can'],
            ['Mineral Water 500ml', 'Water', 0.12, 0.30, null, 'btl'],
            ['Mineral Water 1.5L', 'Water', 0.25, 0.60, null, 'btl'],
            ['Sparkling Water 500ml', 'Water', 0.30, 0.85, 10.00, 'btl'],
            ['Instant Coffee 100g', 'Coffee & Tea', 2.10, 4.50, 10.00, 'pcs'],
            ['Green Tea 25 bags', 'Coffee & Tea', 1.40, 3.20, 10.00, 'box'],
            ['Black Tea 25 bags', 'Coffee & Tea', 1.30, 3.00, 10.00, 'box'],
            ['Potato Chips 150g', 'Chips', 0.80, 1.90, 10.00, 'pack'],
            ['Corn Chips 150g', 'Chips', 0.85, 2.00, 10.00, 'pack'],
            ['Prawn Crackers 100g', 'Chips', 0.60, 1.50, 10.00, 'pack'],
            ['Chocolate Biscuits 200g', 'Biscuits', 1.10, 2.60, 10.00, 'pack'],
            ['Cream Biscuits 200g', 'Biscuits', 1.00, 2.40, 10.00, 'pack'],
            ['Dish Soap 500ml', 'Cleaning', 1.20, 2.75, 10.00, 'btl'],
            ['Floor Cleaner 1L', 'Cleaning', 1.80, 3.90, 10.00, 'btl'],
            ['Toilet Paper 4 rolls', 'Paper Goods', 1.50, 3.20, 10.00, 'pack'],
            ['Paper Towels 2 rolls', 'Paper Goods', 1.30, 2.90, 10.00, 'pack'],
            ['Bar Soap 100g', 'Personal Care', 0.40, 1.00, 10.00, 'pcs'],
            ['Shampoo 400ml', 'Personal Care', 2.30, 5.10, 10.00, 'btl'],
            ['Toothpaste 120g', 'Personal Care', 1.10, 2.50, 10.00, 'pcs'],
        ];

        foreach ($products as $i => [$name, $categoryName, $cost, $sell, $taxRate, $unit]) {
            $seq = $i + 1;

            $product = Product::create([
                'category_id' => $categories[$categoryName]->id,
                'name' => $name,
                'sku' => sprintf('SKU-%04d', $seq),
                'barcode' => sprintf('88510000%04d', $seq),
                'description' => null,
                'cost_price' => $cost,
                'sell_price' => $sell,
                'tax_rate' => $taxRate,
                'unit' => $unit,
                'track_stock' => true,
                'is_active' => true,
            ]);

            Stock::create([
                'product_id' => $product->id,
                'store_id' => $store->id,
                'qty' => 100,
                'low_stock_threshold' => 10,
            ]);
        }
    }

    private function seedSettings(Store $store): void
    {
        $defaults = [
            'receipt_header' => $store->name,
            'receipt_footer' => 'Thank you for shopping with us!',
            'currency_symbol' => '$',
            'currency_code' => 'USD',
            'default_tax_rate' => '10.00',
        ];

        foreach ($defaults as $key => $value) {
            Setting::create(['key' => $key, 'value' => $value]);
        }
    }
}
