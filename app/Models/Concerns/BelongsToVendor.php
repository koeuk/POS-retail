<?php

namespace App\Models\Concerns;

use App\Models\Vendor;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row owned by one vendor (vendor_id; NULL = the admin's own shop).
 *
 * Queries, route bindings and relations only ever see the current tenant's
 * rows, and a row created inside a tenant is stamped with it — so a vendor's
 * new product is its own without the controller saying so. See Tenant.
 */
trait BelongsToVendor
{
    public static function bootBelongsToVendor(): void
    {
        static::addGlobalScope('vendor', function (Builder $query) {
            Tenant::constrain($query, $query->getModel()->qualifyColumn('vendor_id'));
        });

        static::creating(function (self $model) {
            $tenant = Tenant::current();

            if ($tenant !== false && $model->vendor_id === null) {
                $model->vendor_id = $tenant;
            }
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Rows of one vendor, whatever the request is fenced to. */
    public function scopeOfVendor(Builder $query, ?int $vendorId): Builder
    {
        $column = $query->getModel()->qualifyColumn('vendor_id');

        return $query->withoutGlobalScope('vendor')
            ->when($vendorId, fn (Builder $q) => $q->where($column, $vendorId), fn (Builder $q) => $q->whereNull($column));
    }
}
