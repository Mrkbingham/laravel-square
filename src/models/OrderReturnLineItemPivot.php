<?php

namespace Nikolag\Square\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Nikolag\Square\Utils\Constants;

class OrderReturnLineItemPivot extends Model
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
     * @var array
     */
    protected $fillable = [
        'order_return_id',
        'product_id',
        'quantity',
        'square_uid',
        'source_line_item_uid',
        'catalog_object_id',
        'catalog_version',
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
        'total_service_charge_money_amount',
        'total_service_charge_money_currency',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'quantity' => 'integer',
        'catalog_version' => 'integer',
        'base_price_money_amount' => 'integer',
        'variation_total_price_money_amount' => 'integer',
        'gross_return_money_amount' => 'integer',
        'total_discount_money_amount' => 'integer',
        'total_service_charge_money_amount' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Does line item have discount.
     *
     * @param  mixed  $discount
     * @return bool
     */
    public function hasDiscount($discount)
    {
        $val = is_array($discount) ? array_key_exists('id', $discount) ? Discount::find($discount['id']) : $discount : $discount;

        return $this->discounts()->get()->contains($val);
    }

    /**
     * Does line item have tax.
     *
     * @param  mixed  $tax
     * @return bool
     */
    public function hasTax($tax)
    {
        $val = is_array($tax) ? array_key_exists('id', $tax) ? Tax::find($tax['id']) : $tax : $tax;

        return $this->taxes()->get()->contains($val);
    }

    /**
     * Does line item have service charge.
     *
     * @param  mixed  $serviceCharge
     * @return bool
     */
    public function hasServiceCharge($serviceCharge)
    {
        $val = is_array($serviceCharge) ? array_key_exists('id', $serviceCharge) ? ServiceCharge::find($serviceCharge['id']) : $serviceCharge : $serviceCharge;

        return $this->serviceCharges()->get()->contains($val);
    }

    /**
     * Does line item have product.
     *
     * @param  mixed  $product
     * @return bool
     */
    public function hasProduct($product)
    {
        if (is_array($product) && array_key_exists('id', $product)) {
            return $this->product_id == $product['id'];
        }

        if ($product instanceof Product) {
            return $this->product_id == $product->id;
        }

        // Assume it's an ID
        return $this->product_id == $product;
    }

    /**
     * Return order return connected with this line item pivot.
     *
     * @return BelongsTo
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(Constants::ORDER_RETURN_NAMESPACE, 'order_return_id', 'id');
    }

    /**
     * Return product connected with this line item pivot.
     *
     * @return BelongsTo
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Constants::PRODUCT_NAMESPACE, 'product_id', 'id');
    }

    /**
     * Return a list of taxes which are included in this line item.
     *
     * @return MorphToMany
     */
    public function taxes(): MorphToMany
    {
        return $this->morphToMany(Constants::TAX_NAMESPACE, 'featurable', 'nikolag_deductibles', 'featurable_id', 'deductible_id')->where('deductible_type', Constants::TAX_NAMESPACE)->withPivot('scope');
    }

    /**
     * Return a list of discounts which are included in this line item.
     *
     * @return MorphToMany
     */
    public function discounts(): MorphToMany
    {
        return $this->morphToMany(Constants::DISCOUNT_NAMESPACE, 'featurable', 'nikolag_deductibles', 'featurable_id', 'deductible_id')->where('deductible_type', Constants::DISCOUNT_NAMESPACE)->withPivot('scope');
    }

    /**
     * Return a list of service charges which are included in this line item.
     *
     * @return MorphToMany
     */
    public function serviceCharges(): MorphToMany
    {
        return $this->morphToMany(Constants::SERVICE_CHARGE_NAMESPACE, 'featurable', 'nikolag_deductibles', 'featurable_id', 'deductible_id')->where('deductible_type', Constants::SERVICE_CHARGE_NAMESPACE)->withPivot('scope');
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