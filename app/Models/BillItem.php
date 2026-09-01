<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillItem extends Model
{
    use BelongsToCompany;
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',   // tenant scope — must be populated on every create()
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
        'company_id'  => 'integer',
        'category_id' => 'integer',
        'quantity'    => 'integer',
        'unit_price'  => 'decimal:2',
        'total_price' => 'decimal:2',
        'duration'    => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The bill this item belongs to.
     */
    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * The service linked to this line item (nullable — may be a product).
     */
    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The category of the service / product at time of billing.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
