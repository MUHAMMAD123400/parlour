<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountSetting extends Model
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
        'staff_discount_limit',
        'require_discount_reason',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'company_id'               => 'integer',
        'staff_discount_limit'     => 'integer',
        'require_discount_reason'  => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The company this setting record belongs to.
     * (BelongsToCompany trait already defines company(), so this is explicit
     *  for IDE type-hinting and is functionally identical.)
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
