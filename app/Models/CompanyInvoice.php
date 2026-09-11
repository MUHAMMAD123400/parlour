<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class CompanyInvoice extends Model
{
    use SoftDeletes;

    protected $table = 'company_invoices';

    protected $fillable = [
        'invoice_number',
        'company_id',
        'plan_id',
        'billing_cycle',
        'payment_status',
        'date',
        'amount',
        'discount',
        'total_amount',
        'notes',
    ];

    protected $casts = [
        'date'         => 'date:Y-m-d',
        'amount'       => 'decimal:2',
        'discount'     => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    protected $appends = [
        'date_formatted',
        'status_label',
        'cycle_label',
    ];

    protected static function booted()
    {
        static::creating(function ($invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = self::generateNextInvoiceNumber();
            }
        });
    }

    public static function generateNextInvoiceNumber(): string
    {
        $lastInvoice = self::withTrashed()
            ->where('invoice_number', 'like', 'INV-%')
            ->orderByRaw('CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED) DESC')
            ->first();

        if ($lastInvoice && preg_match('/INV-(\d+)/', $lastInvoice->invoice_number, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        } else {
            $nextNumber = 1001;
        }

        return 'INV-' . $nextNumber;
    }

    public function getDateFormattedAttribute(): ?string
    {
        return $this->date ? Carbon::parse($this->date)->format('M d, Y') : null;
    }

    public function getStatusLabelAttribute(): string
    {
        return ucfirst(strtolower($this->payment_status ?? 'pending'));
    }

    public function getCycleLabelAttribute(): string
    {
        return ucfirst(strtolower($this->billing_cycle ?? 'monthly'));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
