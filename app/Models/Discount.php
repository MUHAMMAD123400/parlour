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
}
