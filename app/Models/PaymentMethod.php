<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $table = 'payment_methods';

    protected $fillable = [
        'name',
        'type',
        'bank_name',
        'account_title',
        'account_number',
        'iban',
        'branch_code',
        'branch_name',
        'swift_code',
        'routing_number',
        'qr_code',
        'instructions',
        'display_order',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
        'display_order' => 'integer',
    ];

    protected $appends = [
        'qr_code_url',
    ];

    /**
     * Get the full URL for the QR code.
     */
    public function getQrCodeUrlAttribute(): ?string
    {
        if (empty($this->qr_code)) {
            return null;
        }

        if (filter_var($this->qr_code, FILTER_VALIDATE_URL)) {
            return $this->qr_code;
        }

        return url($this->qr_code);
    }

    /**
     * Scope for active payment methods.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    /**
     * Scope for ordered payment methods.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order', 'asc')->orderBy('id', 'asc');
    }
}
