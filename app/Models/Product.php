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
        'name',
        'sku',
        'barcode',
        'description',
        'cost_price',
        'sell_price',
        'tax_rate',
        'image',
        'unit',
        'track_stock',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
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
