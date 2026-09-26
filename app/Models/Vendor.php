<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A vendor: a business that signs in to run its side of the shop. Created
 * by an admin together with its login (the `owner` account, role `vendor`).
 * The row is the anchor multi-vendor scoping will hang off — products
 * already carry `vendor_id`.
 */
class Vendor extends Model
{
    use HasFactory, HasUuid, RecordsActivity;

    protected $fillable = ['name', 'contact_name', 'phone', 'email', 'address', 'notes', 'is_active'];

    /** Columns the audit trail records changes to — see RecordsActivity. */
    protected array $auditable = [
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Every account on this vendor's team — its own login and its cashiers. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The vendor's own login, created with it on the Vendors screen. The
     * first `vendor`-role account, should an admin ever add a second.
     */
    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->ofMany(
            ['id' => 'min'],
            fn ($query) => $query->where('role', Role::Vendor->value),
        );
    }
}
