<?php

namespace App\Models;

use App\Models\Concerns\BelongsToVendor;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToVendor, HasFactory, HasUuid, RecordsActivity;

    protected $fillable = ['vendor_id', 'name', 'phone', 'email', 'loyalty_points'];

    /** Columns the audit trail records changes to — see RecordsActivity. */
    protected array $auditable = [
        'name',
        'phone',
        'email',
        'loyalty_points',
    ];

    protected function casts(): array
    {
        return ['loyalty_points' => 'integer'];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
