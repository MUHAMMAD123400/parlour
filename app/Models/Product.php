<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
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
        'product_name',
        'brand',
        'category_id',
        'description',
        'quantity_in_stock',
        'unit',
        'purchase_price',
        'selling_price',
        'minimum_stock_alert',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'category_id' => 'integer',
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'quantity_in_stock' => 'integer',
        'minimum_stock_alert' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
