<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Module extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'module_name',
        'permission_module_key',
        'module_description',
        'module_status',
        'module_icon',
    ];

    protected $casts = [
        'module_status' => 'string',
    ];

    public function companyModules(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_modules')
            ->wherePivotNull('deleted_at')
            ->withPivot(['company_module_status', 'deleted_at'])
            ->withTimestamps();
    }
}
