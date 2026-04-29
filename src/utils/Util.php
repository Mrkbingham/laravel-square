<?php

namespace Nikolag\Square\Utils;

use Illuminate\Support\Collection;
use Nikolag\Square\Models\Fulfillment;
use Nikolag\Square\Models\Product;
use stdClass;

class Util
{
    /**
     * Calculates order total based on orderCopy (stdClass of Model).
     *
     * @deprecated Use OrderCalculator::calculateTotalOrderCost() instead.
     *
     * @param stdClass $orderCopy
     *
     * @return float|int
     */
    public static function calculateTotalOrderCost(stdClass $orderCopy): float|int
    {
        return OrderCalculator::calculateTotalOrderCost($orderCopy);
    }

    /**
     * Check if source has fulfillment.
     *
     * @param \Illuminate\Database\Eloquent\Collection|Collection $source
     * @param Fulfillment|int|array|null                          $fulfillment
     *
     * @return bool
     */
    public static function hasFulfillment(
        \Illuminate\Database\Eloquent\Collection|Collection $source,
        Fulfillment|int|array|null $fulfillment
    ): bool {
        if ($fulfillment instanceof Fulfillment) {
            return $source->contains($fulfillment);
        }

        if (is_array($fulfillment)) {
            if (array_key_exists('id', $fulfillment)) {
                return $source->contains(Fulfillment::find($fulfillment['id']));
            }

            if (array_key_exists('name', $fulfillment)) {
                return $source->contains(Fulfillment::where('name', $fulfillment['name'])->first());
            }
        }

        if (is_int($fulfillment)) {
            return $source->contains(Fulfillment::find($fulfillment));
        }

        return false;
    }

    /**
     * Check if source has product.
     *
     * @param \Illuminate\Database\Eloquent\Collection|Collection $source
     * @param int|array|Product|null                              $product
     *
     * @return bool
     */
    public static function hasProduct(\Illuminate\Database\Eloquent\Collection|Collection $source, Product|int|array|null $product): bool
    {
        if ($product instanceof Product) {
            return $source->contains($product);
        }

        if (is_array($product)) {
            if (array_key_exists('id', $product)) {
                return $source->contains(Product::find($product['id']));
            }

            if (array_key_exists('name', $product)) {
                return $source->contains(Product::where('name', $product['name'])->first());
            }
        }

        if (is_int($product)) {
            return $source->contains(Product::find($product));
        }

        return false;
    }

    /**
     * Generate random alphanumeric string of supplied length or 30 by default.
     *
     * @param int $length
     *
     * @throws \Exception
     *
     * @return string
     */
    public static function uid(int $length = 30): string
    {
        return bin2hex(random_bytes($length));
    }
}
