<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    use SoftDeletes;

    protected $table = 'plans';

    protected $fillable = [
        'name',
        'status',
        'staff_limit',
        'customer_limit',
        'monthly',
        'quarterly',
        'yearly',
        'description',
        'features',
    ];

    protected $casts = [
        'status' => 'boolean',
        'staff_limit' => 'string',
        'customer_limit' => 'string',
        'monthly' => 'decimal:2',
        'quarterly' => 'decimal:2',
        'yearly' => 'decimal:2',
    ];

    public function company()
    {
        return $this->hasOne(Company::class, 'plan_id', 'id');
    }

    public function subscriptions()
    {
        return $this->hasMany(CompanySubscribePlan::class, 'plan_id', 'id');
    }

    public function subscriptionHistory()
    {
        return $this->hasMany(CompanySubscribePlanHistory::class, 'plan_id', 'id');
    }
}
