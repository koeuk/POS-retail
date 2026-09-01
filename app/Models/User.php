<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'permissions',
        'store_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Role default, unless this user carries an override for the key.
     * Admins always pass — the shop cannot be locked out of its own admin.
     */
    public function hasPermission(Permission $permission): bool
    {
        if ($this->role === Role::Admin) {
            return true;
        }

        $override = $this->permissions[$permission->value] ?? null;

        return $override !== null
            ? (bool) $override
            : $permission->defaultFor($this->role);
    }

    /** Every permission resolved to its effective value, as {key: bool}. */
    public function effectivePermissions(): array
    {
        return collect(Permission::cases())
            ->mapWithKeys(fn (Permission $p) => [$p->value => $this->hasPermission($p)])
            ->all();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'cashier_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isManager(): bool
    {
        return $this->role === Role::Manager;
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
