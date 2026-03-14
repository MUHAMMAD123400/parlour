<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'offer_name',
        'description',
        'discount_type',
        'discount_value',
        'applies_to',
        'categories',
        'services',
        'valid_from',
        'valid_to',
        'auto_apply',
        'status',
        'usage_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'discount_value' => 'decimal:2',
        'categories' => 'array',
        'services' => 'array',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'auto_apply' => 'boolean',
        'usage_count' => 'integer',
    ];
}
