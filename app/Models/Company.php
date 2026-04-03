<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_name',
        'company_email',
        'company_phone',
        'company_address',
        'company_city',
        'company_state',
        'company_zip',
        'company_country',
        'company_logo',
        'company_website',
        'company_status',
        'company_notes',
        'company_description',
    ];

    protected $casts = [
        'company_status' => 'string',
    ];

    public function companyModules(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'company_modules')
            ->wherePivotNull('deleted_at')
            ->withPivot(['company_module_status', 'deleted_at'])
            ->withTimestamps();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Active permission module keys (matches permissions.module) for this company.
     */
    public function activePermissionModuleKeys(): array
    {
        return $this->modules()
            ->wherePivot('company_module_status', '1')
            ->get()
            ->pluck('permission_module_key')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
