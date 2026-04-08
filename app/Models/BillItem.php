<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'bill_id',
        'service_id',
        'item_name',
        'item_type',
        'category_id',
        'quantity',
        'unit_price',
        'total_price',
        'duration',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'category_id' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'duration' => 'integer',
    ];

    /**
     * Get the bill that owns the item.
     */
    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * Get the service for this item.
     */
    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
