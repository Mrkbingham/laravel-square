<?php

namespace Nikolag\Square\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Square\Models\Builders\OrderReturnBuilder;
use Square\Models\OrderReturn as SquareOrderReturn;

class OrderReturn extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'nikolag_order_returns';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'order_id',
        'source_order_id',
        'data',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    //
    // Relationships
    //

    /**
     * Get the original order associated with this return.
     *
     * @return BelongsTo
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(config('nikolag.connections.square.order.namespace'), 'source_order_id', config('nikolag.connections.square.order.service_identifier'));
    }

    /**
     * Get the square order return data
     *
     * @return Attribute
     */
    protected function data(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value) {
                if (is_null($value)) {
                    return new SquareOrderReturn();
                }
                // Parse the array
                $array = json_decode($value, true);

                // Build a new order return
                return OrderReturnBuilder::init()
                    ->uid($array['uid'] ?? null)
                    ->sourceOrderId($array['source_order_id'] ?? null)
                    ->returnLineItems($array['return_line_items'] ?? null)
                    ->returnServiceCharges($array['return_service_charges'] ?? null)
                    ->returnTaxes($array['return_taxes'] ?? null)
                    ->returnDiscounts($array['return_discounts'] ?? null)
                    ->returnTips($array['return_tips'] ?? null)
                    ->roundingAdjustment($array['rounding_adjustment'] ?? null)
                    ->returnAmounts($array['return_amounts'] ?? null);
            },
            set: fn (SquareOrderReturn $value) => json_encode($value)
        );

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
