<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use BelongsToCompany;
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'service_name',
        'category_id',
        'status',
        'price',
        'duration',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'category_id' => 'integer',
        'price' => 'decimal:2',
        'duration' => 'integer',
        'status' => 'string',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
