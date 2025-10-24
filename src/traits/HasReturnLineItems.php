<?php

namespace Nikolag\Square\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Nikolag\Square\Models\Discount;
use Nikolag\Square\Models\OrderReturnLineItemPivot;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Models\ServiceCharge;
use Nikolag\Square\Models\Tax;
use Nikolag\Square\Utils\Constants;

trait HasReturnLineItems
{
    /**
     * Check existence of an attribute in model.
     *
     * @param  string  $attribute
     * @return bool
     */
    public function hasColumn(string $attribute): bool
    {
        return Schema::hasColumn($this->table, $attribute);
    }

    /**
     * Does an order return have a discount on its line items.
     *
     * @param  mixed  $discount
     * @return bool
     */
    public function hasDiscount(mixed $discount): bool
    {
        $discountId = null;

        if (is_array($discount) && array_key_exists('id', $discount)) {
            $discountId = $discount['id'];
        } elseif ($discount instanceof Discount) {
            $discountId = $discount->id;
        } else {
            $discountId = $discount; // Assume it's an ID
        }

        foreach ($this->returnLineItems as $lineItem) {
            if ($lineItem->discounts()->where('deductible_id', $discountId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does an order return have a tax on its line items.
     *
     * @param  mixed  $tax
     * @return bool
     */
    public function hasTax(mixed $tax): bool
    {
        $taxId = null;

        if (is_array($tax) && array_key_exists('id', $tax)) {
            $taxId = $tax['id'];
        } elseif ($tax instanceof Tax) {
            $taxId = $tax->id;
        } else {
            $taxId = $tax; // Assume it's an ID
        }

        foreach ($this->returnLineItems as $lineItem) {
            if ($lineItem->taxes()->where('deductible_id', $taxId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does an order return have a service charge on its line items.
     *
     * @param  mixed  $serviceCharge
     * @return bool
     */
    public function hasServiceCharge(mixed $serviceCharge): bool
    {
        $serviceChargeId = null;

        if (is_array($serviceCharge) && array_key_exists('id', $serviceCharge)) {
            $serviceChargeId = $serviceCharge['id'];
        } elseif ($serviceCharge instanceof ServiceCharge) {
            $serviceChargeId = $serviceCharge->id;
        } else {
            $serviceChargeId = $serviceCharge; // Assume it's an ID
        }

        foreach ($this->returnLineItems as $lineItem) {
            if ($lineItem->serviceCharges()->where('deductible_id', $serviceChargeId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does an order return have a product.
     *
     * @param  mixed  $product
     * @return bool
     */
    public function hasProduct(mixed $product): bool
    {
        $productId = null;

        if (is_array($product) && array_key_exists('id', $product)) {
            $productId = $product['id'];
        } elseif ($product instanceof Product) {
            $productId = $product->id;
        } else {
            $productId = $product; // Assume it's an ID
        }

        return $this->returnLineItems()->where('product_id', $productId)->exists();
    }

    /**
     * Attach a return line item to the order return.
     *
     * @param mixed $product
     * @param array $attributes
     * @return OrderReturnLineItemPivot
     */
    public function attachReturnLineItem($product, array $attributes = []): OrderReturnLineItemPivot
    {
        $productModel = $product instanceof Product ? $product : Product::find($product);

        // Merge default attributes with provided ones
        $lineItemData = array_merge([
            'order_return_id' => $this->id,
            'product_id' => $productModel->id,
            'quantity' => 1,
            'base_price_money_amount' => $productModel->price ?? 0,
            'base_price_money_currency' => 'USD',
        ], $attributes);

        return OrderReturnLineItemPivot::create($lineItemData);
    }

    /**
     * Does an order return have a specific return line item.
     *
     * @param  mixed  $lineItem
     * @return bool
     */
    public function hasReturnLineItem(mixed $lineItem): bool
    {
        $val = is_array($lineItem) ? (array_key_exists('id', $lineItem) ? OrderReturnLineItemPivot::find($lineItem['id']) : $lineItem) : $lineItem;

        return $this->returnLineItems()->get()->contains($val);
    }

    /**
     * Return a list of line items which are included in this order return.
     *
     * @return HasMany
     */
    public function returnLineItems(): HasMany
    {
        return $this->hasMany(Constants::ORDER_RETURN_LINE_ITEM_NAMESPACE, 'order_return_id', 'id');
    }

    /**
     * Get all products associated with this order return through line items.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function products()
    {
        return $this->returnLineItems()->with('product')->get()->pluck('product');
    }

    /**
     * Get all taxes associated with this order return through line items.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function taxes()
    {
        return $this->returnLineItems()->with('taxes')->get()->flatMap(function ($lineItem) {
            return $lineItem->taxes;
        });
    }

    /**
     * Get all discounts associated with this order return through line items.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function discounts()
    {
        return $this->returnLineItems()->with('discounts')->get()->flatMap(function ($lineItem) {
            return $lineItem->discounts;
        });
    }

    /**
     * Get all service charges associated with this order return through line items.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function serviceCharges()
    {
        return $this->returnLineItems()->with('serviceCharges')->get()->flatMap(function ($lineItem) {
            return $lineItem->serviceCharges;
        });
    }
}