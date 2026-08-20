<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'store_id', 'qty', 'low_stock_threshold'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isLow(): bool
    {
        return $this->low_stock_threshold !== null
            && $this->qty <= $this->low_stock_threshold;
    }

    /**
     * True when offline sales have driven this below zero. Surfaced on the
     * dashboard for reconciliation rather than being silently clamped.
     */
    public function isOversold(): bool
    {
        return $this->qty < 0;
    }
}
