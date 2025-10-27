<?php

namespace Nikolag\Square\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nikolag\Square\Models\OrderReturn;

/**
 * Nikolag\Square\Models\OrderReturnLineItem
 *
 * Represents a single line item within an order return, tracking which specific
 * items and quantities were returned from an original order.
 *
 * @property int $id
 * @property int $order_return_id
 * @property int|null $product_id
 * @property int $quantity
 * @property string $square_uid
 * @property string|null $source_line_item_uid
 * @property string|null $catalog_object_id
 * @property int|null $catalog_version
 * @property string|null $variation_name
 * @property string|null $item_type
 * @property string|null $note
 * @property int|null $base_price_money_amount
 * @property string|null $base_price_money_currency
 * @property int|null $variation_total_price_money_amount
 * @property string|null $variation_total_price_money_currency
 * @property int|null $gross_return_money_amount
 * @property string|null $gross_return_money_currency
 * @property int|null $total_discount_money_amount
 * @property string|null $total_discount_money_currency
 * @property int|null $total_money_amount
 * @property string|null $total_money_currency
 * @property int|null $total_service_charge_money_amount
 * @property string|null $total_service_charge_money_currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OrderReturn $orderReturn
 * @property-read OrderLineItems|null $sourceLineItem
 * @property-read Product|null $product
 */
class OrderReturnLineItem extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'nikolag_order_return_line_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_return_id',
        'product_id',
        'quantity',
        'square_uid',
        'source_line_item_uid',
        'catalog_object_id',
        'catalog_version',
        'name',
        'variation_name',
        'item_type',
        'note',
        'base_price_money_amount',
        'base_price_money_currency',
        'variation_total_price_money_amount',
        'variation_total_price_money_currency',
        'gross_return_money_amount',
        'gross_return_money_currency',
        'total_discount_money_amount',
        'total_discount_money_currency',
        'total_money_amount',
        'total_money_currency',
        'total_service_charge_money_amount',
        'total_service_charge_money_currency',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array{
     *    created_at: 'datetime',
     *    updated_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    //
    // Relationships
    //

    /**
     * Get the order return that this line item belongs to.
     *
     * @return BelongsTo<OrderReturn, $this>
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'order_return_id', 'id');
    }

    /**
     * Get the original line item from the source order that this return line item references.
     *
     * @return BelongsTo<OrderLineItems, $this>
     */
    public function sourceLineItem(): BelongsTo
    {
        return $this->belongsTo(OrderLineItem::class, 'source_line_item_uid', 'square_uid');
    }

    /**
     * Get the product associated with this return line item.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    //
    // Helper Methods
    //

    /**
     * Get the return quantity as an integer.
     */
    public function getReturnQuantity(): int
    {
        return (int) $this->quantity;
    }

    /**
     * Get the gross return amount in dollars.
     */
    public function getGrossReturnAmount(): float
    {
        if (! $this->gross_return_money_amount) {
            return 0.0;
        }

        return $this->gross_return_money_amount / 100;
    }

    /**
     * Get the base price in dollars.
     */
    public function getBasePriceAmount(): float
    {
        if (! $this->base_price_money_amount) {
            return 0.0;
        }

        return $this->base_price_money_amount / 100;
    }

    /**
     * Check if this return line item has a source line item reference.
     */
    public function hasSourceLineItem(): bool
    {
        return ! empty($this->source_line_item_uid);
    }

    /**
     * Check if this return line item is linked to a product in the system.
     */
    public function hasProduct(): bool
    {
        return ! empty($this->product_id);
    }
}
