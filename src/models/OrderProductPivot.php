<?php

namespace Nikolag\Square\Models;

use DateTimeInterface;
use Nikolag\Core\Models\OrderProductPivot as IntermediateTable;
use Nikolag\Square\Models\ServiceCharge;
use Nikolag\Square\Utils\Constants;

class OrderProductPivot extends IntermediateTable
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'quantity',
        'price_money_amount',
        'price_money_currency',
        'square_uid',
        'name',
        'variation_name',
        'catalog_object_id',
        'catalog_version',
        'item_type',
        'note',
        'variation_total_price_money_amount',
        'variation_total_price_money_currency',
        'gross_sales_money_amount',
        'gross_sales_money_currency',
        'total_tax_money_amount',
        'total_tax_money_currency',
        'total_discount_money_amount',
        'total_discount_money_currency',
        'total_money_amount',
        'total_money_currency',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'quantity' => 'integer',
        'price_money_amount' => 'integer',
        'catalog_version' => 'integer',
        'variation_total_price_money_amount' => 'integer',
        'gross_sales_money_amount' => 'integer',
        'total_tax_money_amount' => 'integer',
        'total_discount_money_amount' => 'integer',
        'total_money_amount' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the name of the "created at" column.
     *
     * @return string
     */
    public function getCreatedAtColumn()
    {
        return static::CREATED_AT;
    }

    /**
     * Get the name of the "updated at" column.
     *
     * @return string
     */
    public function getUpdatedAtColumn()
    {
        return static::UPDATED_AT;
    }

    /**
     * Does intermediate table has discount.
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
     * Does intermediate table has tax.
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
     * Does intermediate table has service charge.
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
     * Does intermediate table has product.
     *
     * @param  mixed  $product
     * @return bool
     */
    public function hasProduct($product)
    {
        $val = is_array($product) ? array_key_exists('id', $product) ? Product::find($product['id']) : $product : $product;

        return $this->product === $val;
    }

    /**
     * Associates a modifier
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function modifiers()
    {
        return $this->hasMany(OrderProductModifierPivot::class, 'order_product_id', 'id');
    }

    /**
     * Return order connected with this product pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order()
    {
        return $this->belongsTo(config('nikolag.connections.square.order.namespace'), 'order_id', config('nikolag.connections.square.order.identifier'));
    }

    /**
     * Return product connected with this product pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Constants::PRODUCT_NAMESPACE, 'product_id', 'id');
    }

    /**
     * Return a list of taxes which are in included in this product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function taxes()
    {
        return $this->morphToMany(Constants::TAX_NAMESPACE, 'featurable', 'nikolag_deductibles', 'featurable_id', 'deductible_id')->where('deductible_type', Constants::TAX_NAMESPACE)->withPivot('scope');
    }

    /**
     * Return a list of discounts which are in included in this product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function discounts()
    {
        return $this->morphToMany(Constants::DISCOUNT_NAMESPACE, 'featurable', 'nikolag_deductibles', 'featurable_id', 'deductible_id')->where('deductible_type', Constants::DISCOUNT_NAMESPACE)->withPivot('scope');
    }

    /**
     * Return a list of service charges which are included in this product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function serviceCharges()
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
