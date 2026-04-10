<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Create respecting company_id uniqueness (Spatie only checks name + guard).
     */
    public static function create(array $attributes = [])
    {
        $attributes['guard_name'] ??= Guard::getDefaultName(static::class);

        $params = [
            'name' => $attributes['name'],
            'guard_name' => $attributes['guard_name'],
            'company_id' => $attributes['company_id'] ?? null,
        ];

        if (static::query()->where($params)->exists()) {
            throw RoleAlreadyExists::create($attributes['name'], $attributes['guard_name']);
        }

        return static::query()->create($attributes);
    }

    /**
     * Resolve by name: super_admin is global (company_id null). Tenant roles resolve via the
     * authenticated user's company when present; otherwise global-null roles only.
     */
    public static function findByName(string $name, ?string $guardName = null): RoleContract
    {
        $guardName ??= Guard::getDefaultName(static::class);

        if ($name === 'super_admin') {
            $role = static::query()
                ->where('name', $name)
                ->where('guard_name', $guardName)
                ->whereNull('company_id')
                ->first();
        } elseif (Auth::check() && Auth::user()->company_id) {
            $role = static::query()
                ->where('name', $name)
                ->where('guard_name', $guardName)
                ->where('company_id', Auth::user()->company_id)
                ->first();
        } else {
            $role = static::query()
                ->where('name', $name)
                ->where('guard_name', $guardName)
                ->whereNull('company_id')
                ->first();
        }

        if (! $role) {
            throw RoleDoesNotExist::named($name, $guardName);
        }

        return $role;
    }
}
