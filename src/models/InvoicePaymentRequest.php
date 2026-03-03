<?php

namespace Nikolag\Square\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePaymentRequest extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'nikolag_invoice_payment_requests';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'invoice_id',
        'square_uid',
        'request_type',
        'due_date',
        'tipping_enabled',
        'automatic_payment_source',
        'computed_amount_money_amount',
        'computed_amount_money_currency',
        'total_completed_amount_money_amount',
        'total_completed_amount_money_currency',
        'rounding_adjustment_amount',
        'rounding_adjustment_currency',
        'request_method',
        'fixed_amount_requested_money_amount',
        'fixed_amount_requested_money_currency',
        'percentage_requested',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'due_date' => 'date',
        'tipping_enabled' => 'boolean',
        'computed_amount_money_amount' => 'integer',
        'total_completed_amount_money_amount' => 'integer',
        'rounding_adjustment_amount' => 'integer',
        'fixed_amount_requested_money_amount' => 'integer',
    ];

    /**
     * Get the invoice that owns the payment request.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}
