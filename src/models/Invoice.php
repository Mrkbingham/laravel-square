<?php

namespace Nikolag\Square\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Nikolag\Square\Exceptions\InvalidSquareOrderException;

class Invoice extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'nikolag_invoices';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'payment_service_id',
        'payment_service_version',
        'order_id',
        'location_id',
        'invoice_number',
        'title',
        'description',
        'scheduled_at',
        'public_url',
        'status',
        'delivery_method',
        'timezone',
        'subscription_id',
        'sale_or_service_date',
        'payment_conditions',
        'store_payment_method_enabled',
        'creator_team_member_id',
        'next_payment_amount_money_amount',
        'next_payment_amount_money_currency',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'scheduled_at' => 'datetime',
        'sale_or_service_date' => 'date',
        'store_payment_method_enabled' => 'boolean',
        'next_payment_amount_money_amount' => 'integer',
        'payment_service_version' => 'integer',
        'square_created_at' => 'datetime',
        'square_updated_at' => 'datetime',
    ];

    /**
     * Get the order that owns the invoice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(config('nikolag.connections.square.order.namespace'), 'order_id');
    }

    /**
     * Get the location that owns the invoice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * Get the invoice recipient.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function recipient(): HasOne
    {
        return $this->hasOne(InvoiceRecipient::class, 'invoice_id');
    }

    /**
     * Get the invoice payment requests.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function paymentRequests(): HasMany
    {
        return $this->hasMany(InvoicePaymentRequest::class, 'invoice_id');
    }

    /**
     * Get the invoice accepted payment methods.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function acceptedPaymentMethods(): HasOne
    {
        return $this->hasOne(InvoiceAcceptedPaymentMethods::class, 'invoice_id');
    }

    /**
     * Get the invoice custom fields.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function customFields(): HasMany
    {
        return $this->hasMany(InvoiceCustomField::class, 'invoice_id');
    }

    /**
     * Get the invoice attachments.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(InvoiceAttachment::class, 'invoice_id');
    }

    /**
     * Check if the invoice is in a terminal state.
     * Terminal states: PAID, REFUNDED, CANCELED, FAILED
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['PAID', 'REFUNDED', 'CANCELED', 'FAILED']);
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
