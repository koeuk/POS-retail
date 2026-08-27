<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use Illuminate\Database\Seeder;

/**
 * A pretend shelf for trying the app out. Never run by default — a real shop
 * starts empty and enters its own catalogue.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::firstOrFail();

        $this->seedProducts($store, $this->seedCategories());
    }

    /** @return array<string, Category> */
    private function seedCategories(): array
    {
        $names = [
            'Soft Drinks',
            'Water',
            'Coffee & Tea',
            'Chips',
            'Biscuits',
            'Cleaning',
            'Paper Goods',
            'Personal Care',
        ];

        $flat = [];

        foreach ($names as $name) {
            $flat[$name] = Category::create(['name' => $name]);
        }

        return $flat;
    }

    /** @param array<string, Category> $categories */
    private function seedProducts(Store $store, array $categories): void
    {
        // [name, category, cost, sell, unit]
        $products = [
            ['Cola 330ml', 'Soft Drinks', 0.35, 0.75, 'can'],
            ['Cola 1.5L', 'Soft Drinks', 0.90, 1.80, 'btl'],
            ['Orange Soda 330ml', 'Soft Drinks', 0.33, 0.70, 'can'],
            ['Lemon Soda 330ml', 'Soft Drinks', 0.33, 0.70, 'can'],
            ['Mineral Water 500ml', 'Water', 0.12, 0.30, 'btl'],
            ['Mineral Water 1.5L', 'Water', 0.25, 0.60, 'btl'],
            ['Sparkling Water 500ml', 'Water', 0.30, 0.85, 'btl'],
            ['Instant Coffee 100g', 'Coffee & Tea', 2.10, 4.50, 'pcs'],
            ['Green Tea 25 bags', 'Coffee & Tea', 1.40, 3.20, 'box'],
            ['Black Tea 25 bags', 'Coffee & Tea', 1.30, 3.00, 'box'],
            ['Potato Chips 150g', 'Chips', 0.80, 1.90, 'pack'],
            ['Corn Chips 150g', 'Chips', 0.85, 2.00, 'pack'],
            ['Prawn Crackers 100g', 'Chips', 0.60, 1.50, 'pack'],
            ['Chocolate Biscuits 200g', 'Biscuits', 1.10, 2.60, 'pack'],
            ['Cream Biscuits 200g', 'Biscuits', 1.00, 2.40, 'pack'],
            ['Dish Soap 500ml', 'Cleaning', 1.20, 2.75, 'btl'],
            ['Floor Cleaner 1L', 'Cleaning', 1.80, 3.90, 'btl'],
            ['Toilet Paper 4 rolls', 'Paper Goods', 1.50, 3.20, 'pack'],
            ['Paper Towels 2 rolls', 'Paper Goods', 1.30, 2.90, 'pack'],
            ['Bar Soap 100g', 'Personal Care', 0.40, 1.00, 'pcs'],
            ['Shampoo 400ml', 'Personal Care', 2.30, 5.10, 'btl'],
            ['Toothpaste 120g', 'Personal Care', 1.10, 2.50, 'pcs'],
        ];

        foreach ($products as $i => [$name, $categoryName, $cost, $sell, $unit]) {
            $seq = $i + 1;

            $product = Product::create([
                'category_id' => $categories[$categoryName]->id,
                'name' => $name,
                'sku' => sprintf('SKU-%04d', $seq),
                'barcode' => sprintf('88510000%04d', $seq),
                'description' => null,
                'cost_price' => $cost,
                'sell_price' => $sell,
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
}
