<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
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
        'company_id' => 'integer',
        'discount_value' => 'decimal:2',
        'categories' => 'array',
        'services' => 'array',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'auto_apply' => 'boolean',
        'usage_count' => 'integer',
    ];

    /**
     * Get the service models associated with this discount
     * Since services are stored as JSON array of IDs, we query Service model directly
     * Access via: $discount->serviceModels
     */
    public function getServiceModelsAttribute()
    {
        // Access raw attribute value from attributes array (before casting)
        $serviceIds = $this->attributes['services'] ?? null;
        
        if (empty($serviceIds)) {
            return collect([]);
        }
        
        // Decode JSON string to array
        if (is_string($serviceIds)) {
            $serviceIds = json_decode($serviceIds, true);
        }
        
        if (empty($serviceIds) || !is_array($serviceIds)) {
            return collect([]);
        }
        
        return Service::whereIn('id', $serviceIds)->get();
    }

    public function getCategoryModelsAttribute()
    {
        $categoryIds = $this->attributes['categories'] ?? null;

        if (empty($categoryIds)) {
            return collect([]);
        }

        if (is_string($categoryIds)) {
            $categoryIds = json_decode($categoryIds, true);
        }

        if (empty($categoryIds) || !is_array($categoryIds)) {
            return collect([]);
        }

        return Category::whereIn('id', $categoryIds)->get();
    }
}
