<?php

namespace Nikolag\Square\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Nikolag\Square\Traits\HasReturnLineItems;
use Square\Models\Builders\MoneyBuilder;
use Square\Models\Builders\OrderReturnBuilder;
use Square\Models\Builders\OrderReturnLineItemBuilder;
use Square\Models\OrderReturn as SquareOrderReturn;

class OrderReturn extends Model
{
    use HasReturnLineItems;
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

    //
    // Attribute accessors & accessor helpers
    //

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

                // Build the line items objects
                $returnLineItems = $this->buildLineItems($array['return_line_items'] ?? []);

                // Build a new order return
                return OrderReturnBuilder::init()
                    ->uid($array['uid'] ?? null)
                    ->sourceOrderId($array['source_order_id'] ?? null)
                    ->returnLineItems($returnLineItems->all())
                    ->returnServiceCharges($array['return_service_charges'] ?? null)
                    ->returnTaxes($array['return_taxes'] ?? null)
                    ->returnDiscounts($array['return_discounts'] ?? null)
                    ->returnTips($array['return_tips'] ?? null)
                    ->roundingAdjustment($array['rounding_adjustment'] ?? null)
                    ->returnAmounts($array['return_amounts'] ?? null)
                    ->build();
            },
            set: fn (SquareOrderReturn $value) => json_encode($value)
        );
    }

    /**
     * Builds line item return objects from the return data
     *
     * @param array $returnLineItems The array of return line items from the data object.
     *
     * @return Collection<int, OrderReturnLineItem>
     */
    public function buildLineItems(array $returnLineItems): Collection
    {
        return collect($returnLineItems)->map(function ($item) {
                return OrderReturnLineItemBuilder::init($item['quantity'])
                    ->uid($item['uid'] ?? null)
                    ->sourceLineItemUid($item['source_line_item_uid'] ?? null)
                    ->name($item['name'] ?? null)
                    // ->quantityUnit(?OrderQuantityUnit $value)
                    ->note($item['note'] ?? null)
                    ->catalogObjectId($item['catalog_object_id'] ?? null)
                    ->catalogVersion($item['catalog_version'] ?? null)
                    ->variationName($item['variation_name'] ?? null)
                    ->itemType($item['item_type'] ?? null)
                    // ->returnModifiers(?array $value)
                    // ->appliedTaxes(?array $value)
                    // ->appliedDiscounts(?array $value)
                    ->basePriceMoney(
                        MoneyBuilder::init()
                            ->amount($item['base_price_money']['amount'] ?? 0)
                            ->currency($item['base_price_money']['currency'] ?? 'USD')
                            ->build()
                    )
                    ->variationTotalPriceMoney(
                        MoneyBuilder::init()
                            ->amount($item['variation_total_price_money']['amount'] ?? 0)
                            ->currency($item['variation_total_price_money']['currency'] ?? 'USD')
                            ->build()
                    )
                    ->grossReturnMoney(
                        MoneyBuilder::init()
                            ->amount($item['gross_return_money']['amount'] ?? 0)
                            ->currency($item['gross_return_money']['currency'] ?? 'USD')
                            ->build()
                    )
                    // ->totalTaxMoney(
                    //     MoneyBuilder::init()->amount(0_00)->currency('USD')->build()
                    // )
                    ->totalDiscountMoney(
                        MoneyBuilder::init()
                            ->amount($item['total_discount_money']['amount'] ?? 0)
                            ->currency($item['total_discount_money']['currency'] ?? 'USD')
                            ->build()
                    )
                    // ->totalMoney(
                    //     MoneyBuilder::init()->amount($perItemCost * $quantity)->currency('USD')->build()
                    // )
                    // ->appliedServiceCharges(?array $value)
                    ->totalServiceChargeMoney(
                        MoneyBuilder::init()
                            ->amount($item['total_service_charge_money']['amount'] ?? 0)
                            ->currency($item['total_service_charge_money']['currency'] ?? 'USD')
                            ->build()
                    )
                    ->build();
            });
    }

    public function getLineItems()
    {
        return $this->data->getReturnLineItems();
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
