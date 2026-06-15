<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySubscribePlanHistory extends Model
{
    protected $fillable = [
        'company_id',
        'plan_id',
        'start_date',
        'end_date',
        'action',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'action' => 'string',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }
}
