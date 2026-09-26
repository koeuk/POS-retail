<?php

namespace App\Models\Concerns;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * A row owned through its store — orders, registers, stock, movements. Its
 * vendor is its store's vendor, so it is fenced to the tenant's stores.
 */
trait ScopedByStore
{
    public static function bootScopedByStore(): void
    {
        static::addGlobalScope('vendor', function (Builder $query) {
            Tenant::constrainStore($query, $query->getModel()->qualifyColumn('store_id'));
        });
    }
}
