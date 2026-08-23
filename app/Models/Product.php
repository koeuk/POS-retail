<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'parent_product_id',
        'name',
        'sku',
        'barcode',
        'description',
        'cost_price',
        'sell_price',
        'tax_rate',
        'image',
        'unit',
        'units_per_pack',
        'track_stock',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'units_per_pack' => 'integer',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /** The product this one is a pack of, if any. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    /** The pack sizes sold against this product — case, six-pack, single. */
    public function packs(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_product_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventoryLogs(): HasMany
    {
        return $this->hasMany(InventoryLog::class);
    }

    /** Stock row for one store, for eager loading on the POS product feed. */
    public function stockFor(int $storeId): HasOne
    {
        return $this->hasOne(Stock::class)->where('store_id', $storeId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Only products that hold stock in their own right. */
    public function scopeBase(Builder $query): Builder
    {
        return $query->whereNull('parent_product_id');
    }

    public function isPack(): bool
    {
        return $this->parent_product_id !== null;
    }

    /**
     * The row whose stock this product moves.
     *
     * A case of beer has no shelf of its own — selling one takes 24 cans off
     * the base product. Everything to do with stock goes through here rather
     * than reaching for `$product->id` directly.
     */
    public function stockProductId(): int
    {
        return $this->parent_product_id ?? $this->id;
    }

    /** Base units consumed by selling `$qty` of this product. */
    public function baseUnits(int $qty = 1): int
    {
        return $qty * max(1, $this->units_per_pack);
    }

    /**
     * The rate this product is actually taxed at.
     *
     * Tax is not edited per product — the form carries a single price field —
     * so a null rate inherits `default_tax_rate` from settings. An explicit
     * 0.00 still means zero-rated and is never overridden, which is how a
     * product can opt out of tax entirely.
     *
     * Settings are cached forever and invalidated on save, so calling this in
     * a loop over the whole catalogue costs one query, not one per product.
     */
    public function effectiveTaxRate(): float
    {
        if ($this->tax_rate !== null) {
            return (float) $this->tax_rate;
        }

        return (float) (Setting::get('default_tax_rate') ?? 0);
    }
}
