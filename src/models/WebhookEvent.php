<?php

namespace Nikolag\Square\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'nikolag_webhook_events';

    /**
     * Status constants for webhook events.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'square_event_id',
        'event_type',
        'event_data',
        'event_time',
        'status',
        'processed_at',
        'error_message',
        'webhook_subscription_id',
        'retry_reason',
        'retry_number',
        'initial_delivery_timestamp',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'event_data' => 'array',
        'event_time' => 'datetime',
        'processed_at' => 'datetime',
        'initial_delivery_timestamp' => 'datetime',
    ];

    /**
     * The webhook subscription that received this event.
     *
     * @return BelongsTo
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }

    /**
     * Scope a query to only include pending events.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePending($query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include processed events.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeProcessed($query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSED);
    }

    /**
     * Scope a query to only include failed events.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeFailed($query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope a query to only include events of a specific type.
     *
     * @param Builder $query
     * @param string $eventType
     * @return Builder
     */
    public function scopeForEventType($query, string $eventType): Builder
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Check if this is an catalog-related event.
     *
     * @return bool
     */
    public function isCatalogEvent(): bool {
        return str_starts_with($this->event_type, 'catalog.');
    }

    /**
     * Check if this is an customer-related event.
     *
     * @return bool
     */
    public function isCustomerEvent(): bool {
        return str_starts_with($this->event_type, 'customer.');
    }
    /**
     * Check if this is an invoice-related event.
     *
     * @return bool
     */
    public function isInvoiceEvent(): bool {
        return str_starts_with($this->event_type, 'invoice.');
    }
    /**
     * Check if this is an location-related event.
     *
     * @return bool
     */
    public function isLocationEvent(): bool {
        return str_starts_with($this->event_type, 'location.');
    }

    /**
     * Check if this is an oauth-related event.
     *
     * @return bool
     */
    public function isOAuthEvent(): bool {
        return str_starts_with($this->event_type, 'oauth.');
    }
    /**
     * Check if this is an order-related event.
     *
     * @return bool
     */
    public function isOrderEvent(): bool {
        return str_starts_with($this->event_type, 'order.');
    }
    /**
     * Check if this is an refund-related event.
     *
     * @return bool
     */
    public function isRefundEvent(): bool {
        return str_starts_with($this->event_type, 'refund.');
    }

    /**
     * Check if this is a payment-related event.
     *
     * @return bool
     */
    public function isPaymentEvent(): bool
    {
        return str_starts_with($this->event_type, 'payment.');
    }

    /**
     * Get the object type key for the event data based on event type.
     *
     * Different Square webhook event types store their data under different keys
     * in the event_data['data']['object'] structure. This method maps event types
     * to their corresponding object keys.
     *
     * @return string|null The object key for this event type, or null if unknown
     */
    public static function getObjectTypeKey(string $eventType): ?string
    {
        return match($eventType) {
            'order.created' => 'order_created',
            'order.fulfillment.updated' => 'order_fulfillment_updated',
            'order.updated' => 'order_updated',
            'payment.created' => 'payment',
            'payment.updated' => 'payment',
            'refund.created' => 'refund',
            'refund.updated' => 'refund',
            default => null,
        };
    }

    /**
     * Get the order ID from the event data.
     *
     * @return string|null
     */
    public function getOrderId(): ?string
    {
        $eventObject = $this->getEventObject();
        return $eventObject[self::getObjectTypeKey($this->event_type)]['order_id'] ?? null;
    }

    /**
     * Get the payment ID from the event data.
     *
     * @return string|null
     */
    public function getPaymentId(): ?string
    {
        $eventObject = $this->getEventObject();
        return $eventObject['payment']['id'] ?? null;
    }

    /**
     * Get the merchant ID from the event data.
     *
     * @return string|null
     */
    public function getMerchantId(): ?string
    {
        return $this->event_data['merchant_id'] ?? null;
    }

    /**
     * Get the location ID from the event data.
     *
     * @return string|null
     */
    public function getLocationId(): ?string
    {
        $eventObject = $this->getEventObject();
        return $eventObject[self::getObjectTypeKey($this->event_type)]['location_id'] ?? null;
    }

    /**
     * Mark the event as processed.
     *
     * @return bool
     */
    public function markAsProcessed(): bool
    {
        return $this->update([
            'status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
            'error_message' => null,
        ]);
    }

    /**
     * Mark the event as failed with an error message.
     *
     * @param string $errorMessage
     * @return bool
     */
    public function markAsFailed(string $errorMessage): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'processed_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Check if the event is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the event has been processed.
     *
     * @return bool
     */
    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    /**
     * Check if the event processing failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Get the event data object for easy access.
     *
     * @return array|null
     */
    public function getEventObject(): ?array
    {
        return $this->event_data['data']['object'] ?? null;
    }

    /**
     * Check if this webhook event is a retry attempt.
     *
     * @return bool
     */
    public function isRetry(): bool
    {
        return !is_null($this->retry_number) && $this->retry_number > 0;
    }

    /**
     * Get retry information for this webhook event.
     *
     * @return array|null
     */
    public function getRetryInfo(): ?array
    {
        if (!$this->isRetry()) {
            return null;
        }

        return [
            'reason' => $this->retry_reason,
            'number' => $this->retry_number,
            'initial_delivery_timestamp' => $this->initial_delivery_timestamp,
        ];
    }

    /**
     * Scope a query to only include retry events.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeRetries($query): Builder
    {
        return $query->whereNotNull('retry_number')->where('retry_number', '>', 0);
    }

    /**
     * Scope a query to only include original (non-retry) events.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOriginal($query): Builder
    {
        return $query->whereNull('retry_number');
    }

    /**
     * Get a human-readable description of the event.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $eventType = $this->event_type;
        $description = '';

        if ($this->isOrderEvent()) {
            $orderId = $this->getOrderId();
            $description = "Order event ({$eventType}) for order {$orderId}";
        } elseif ($this->isPaymentEvent()) {
            $paymentId = $this->getPaymentId();
            $description = "Payment event ({$eventType}) for payment {$paymentId}";
        } else {
            $description = "Webhook event ({$eventType})";
        }

        if ($this->isRetry()) {
            $description .= " (retry #{$this->retry_number})";
        }

        return $description;
    }
}
