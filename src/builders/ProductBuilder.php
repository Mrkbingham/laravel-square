<?php

namespace Nikolag\Square\Builders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Nikolag\Square\Exceptions\InvalidSquareOrderException;
use Nikolag\Square\Exceptions\MissingPropertyException;
use Nikolag\Square\Models\OrderProductPivot;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Utils\Constants;
use stdClass;

class ProductBuilder
{
    /**
     * @var DiscountBuilder
     */
    private DiscountBuilder $discountBuilder;
    /**
     * @var ModifiersBuilder
     */
    private ModifiersBuilder $modifiersBuilder;
    /**
     * @var TaxesBuilder
     */
    private TaxesBuilder $taxesBuilder;

    public function __construct()
    {
        $this->discountBuilder = new DiscountBuilder();
        $this->modifiersBuilder = new ModifiersBuilder();
        $this->taxesBuilder = new TaxesBuilder();
    }

    /**
     * Add a product to the order from model as source.
     *
     * @param  Model $order
     * @param  Model $product
     * @param  int   $quantity
     * @param  array $modifiers
     * @return Product|stdClass
     *
     * @throws InvalidSquareOrderException
     * @throws MissingPropertyException
     */
    public function addProductFromModel(Model $order, Model $product, int $quantity, array $modifiers = []): Product|stdClass
    {
        try {
            // If quantity is null or 0
            if ($quantity == null || $quantity == 0) {
                // throw exception
                throw new MissingPropertyException('$quantity property is missing on Product', 500);
            }

            $productCopy = $this->createProductFromModel($product, $order, $quantity, $modifiers);
            // Create discounts Collection
            $productCopy->discounts = collect([]);
            // //Discounts
            if ($product->discounts && $product->discounts->isNotEmpty()) {
                $productCopy->discounts = $this->discountBuilder->createDiscounts($product->discounts->toArray(), Constants::DEDUCTIBLE_SCOPE_PRODUCT, $productCopy->product);
            }
            // Create taxes Collection
            $productCopy->taxes = collect([]);
            //Taxes
            if ($product->taxes && $product->taxes->isNotEmpty()) {
                $productCopy->taxes = $this->taxesBuilder->createTaxes($product->taxes->toArray(), Constants::DEDUCTIBLE_SCOPE_PRODUCT, $productCopy->product);
            }

            return $productCopy;
        } catch (MissingPropertyException $e) {
            throw new MissingPropertyException('Required field is missing', 500, $e);
        }
    }

    /**
     * Add a product to the order from array as source.
     *
     * @param  stdClass  $orderCopy
     * @param  Model  $order
     * @param  array  $product
     * @param  int  $quantity
     * @return Product|stdClass
     *
     * @throws InvalidSquareOrderException
     * @throws MissingPropertyException
     */
    public function addProductFromArray(stdClass $orderCopy, Model $order, array $product, int $quantity): Product|stdClass
    {
        try {
            // Get quantity
            $tempQuantity = null;

            // If $product has quantity
            if (Arr::has($product, 'quantity')) {
                $tempQuantity = $product['quantity'];
            }

            // If quantity is null or 0
            if ($tempQuantity == null || $tempQuantity == 0) {
                // Set new quantity for checks
                $tempQuantity = $quantity;
                // Update quantity for product
                $product['quantity'] = $tempQuantity;
            }

            if ($tempQuantity == null || $tempQuantity == 0) {
                throw new MissingPropertyException('$quantity property is missing on Product', 500);
            }

            $productCopy = $this->createProductFromArray($product, $order);
            // Create taxes Collection
            $productCopy->discounts = collect([]);
            //Discounts
            if (Arr::has($product, 'discounts')) {
                $productCopy->discounts = $this->discountBuilder->createDiscounts($product['discounts'], Constants::DEDUCTIBLE_SCOPE_PRODUCT, $productCopy->productPivot);
                $productCopy->discounts->each(function ($discount) use ($orderCopy) {
                    if (! $orderCopy->discounts->contains($discount)) {
                        $orderCopy->discounts->add($discount);
                    }
                });
            }
            // Create taxes Collection
            $productCopy->taxes = collect([]);
            //Taxes
            if (Arr::has($product, 'taxes')) {
                $productCopy->taxes = $this->taxesBuilder->createTaxes($product['taxes'], Constants::DEDUCTIBLE_SCOPE_PRODUCT, $productCopy->productPivot);
                $productCopy->taxes->each(function ($tax) use ($orderCopy) {
                    if (! $orderCopy->taxes->contains($tax)) {
                        $orderCopy->taxes->add($tax);
                    }
                });
            }

            return $productCopy;
        } catch (MissingPropertyException $e) {
            throw new MissingPropertyException('Required field is missing', 500, $e);
        }
    }

    /**
     * Create product from array.
     *
     * @param  array  $product
     * @param  Model|null  $order
     * @return Product|stdClass
     *
     * @throws MissingPropertyException
     */
    public function createProductFromArray(array $product, Model $order = null): Product|stdClass
    {
        $productObj = new stdClass();
        //If product doesn't have quantity in pivot table
        //throw new exception because every product should
        //have at least 1 quantity
        if (! Arr::has($product, 'quantity') || $product['quantity'] == null || $product['quantity'] == 0) {
            throw new MissingPropertyException('$quantity property for object Product is missing', 500);
        }
        //Check if order is present and if already has this product
        //or if product doesn't have property $id then create new Product object
        if (($order && ! $order->hasProduct($product)) || ! Arr::has($product, 'id')) {
            $tempProduct = new Product($product);
            $productPivot = new OrderProductPivot($product);
        } else {
            $tempProduct = Product::find($product['id']);
            $productPivot = OrderProductPivot::where('order_id', $order->id)->where('product_id', $tempProduct->id)->first();
            if (! $productPivot) {
                $productPivot = new OrderProductPivot($product);
            }
        }

        $productObj = $tempProduct;
        $productObj->pivot = $productPivot;

        return $productObj;
    }

    /**
     * Create product from model.
     *
     * @param  Model  $product
     * @param  Model|null  $order
     * @param  int|null  $quantity
     * @param  array  $modifiers
     * @return Product|stdClass
     *
     * @throws MissingPropertyException
     */
    public function createProductFromModel(Model $product, Model $order = null, int $quantity = null, array $modifiers = []): Product|stdClass
    {
        $productObj = new stdClass();
        //If product doesn't have quantity in pivot table
        //throw new exception because every product should
        //have at least 1 quantity
        if (! $quantity) {
            if (! $product->pivot->quantity || $product->pivot->quantity == null || $product->pivot->quantity == 0) {
                throw new MissingPropertyException('$quantity property for object Product is missing', 500);
            } else {
                $quantity = $product->pivot->quantity;
            }
        }
        // Check if order is present and if already has this product
        // or if product doesn't have property $id then create new Product object
        if (($order && ! $order->hasProduct($product)) && ! Arr::has($product->toArray(), 'id')) {
            $tempProduct = new Product($product->toArray());
            $productPivot = new OrderProductPivot($product->toArray());
        } else {
            $tempProduct = Product::find($product->id);
            $productPivot = OrderProductPivot::where('order_id', $order->id)->where('product_id', $tempProduct->id)->first();
            if (! $productPivot) {
                $productPivot = new OrderProductPivot($product->toArray());
            }
        }

        // Add modifiers to the order product pivot
        if ($modifiers) {
            $productPivot = $this->modifiersBuilder->addModifiers($productPivot, $modifiers);
        }

        $productPivot->quantity = $quantity;
        $productObj = $tempProduct;
        $productObj->pivot = $productPivot;

        return $productObj;
    }
}
