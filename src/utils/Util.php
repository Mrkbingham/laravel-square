<?php

namespace Nikolag\Square\Utils;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nikolag\Square\Models\Fulfillment;
use Nikolag\Square\Models\OrderProductPivot;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Models\Tax;
use Square\Models\OrderServiceChargeCalculationPhase;
use Square\Models\OrderServiceChargeTreatmentType;
use stdClass;

class Util
{
    /**
     * Calculates order total based on orderCopy (stdClass of Model).
     *
     * @param stdClass $orderCopy
     *
     * @return float|int
     */
    public static function calculateTotalOrderCost(stdClass $orderCopy): float|int
    {
        $allServiceCharges = self::collectServiceCharges($orderCopy);

        return self::_calculateTotalCost($orderCopy->discounts, $orderCopy->taxes, $allServiceCharges, $orderCopy->products);
    }

    /**
     * Calculate all discounts on order level no matter
     * their scope.
     *
     * @param Collection $discounts
     * @param float      $noDeductiblesCost
     * @param Collection $products
     *
     * @return float|int
     */
    private static function _calculateDiscounts(Collection $discounts, float $noDeductiblesCost, Collection $products): float|int
    {
        if ($discounts->isEmpty() || $products->isEmpty()) {
            return 0;
        }

        return $discounts->sum(function ($discount) use ($products, $noDeductiblesCost) {
            $scope = $discount->pivot ? $discount->pivot->scope : $discount->scope;

            return match ($scope) {
                Constants::DEDUCTIBLE_SCOPE_PRODUCT => self::_calculateProductDiscounts($products, $discount),
                Constants::DEDUCTIBLE_SCOPE_ORDER   => self::_calculateOrderDiscounts($discount, $noDeductiblesCost),
                default                             => 0
            };
        });
    }

    /**
     * Function which calculates the net price by removing any additive taxes to the entire order.
     *
     * @param float      $discountCount
     * @param Collection $inclusiveTaxes
     *
     * @return float|int
     */
    private static function _calculateNetPrice(float $discountCost, Collection $inclusiveTaxes): float|int
    {
        // Get all the inclusive taxes
        $inclusiveTaxPercent = $inclusiveTaxes->filter(function ($tax) {
            return $tax->type === Constants::TAX_INCLUSIVE;
        })->map(function ($tax) {
            return $tax->percentage;
        })->pipe(function ($total) {
            return $total->sum();
        }) / 100;

        // Calculate the net price (amount without inclusive tax)
        $netPrice = $discountCost / (1 + $inclusiveTaxPercent);

        return $netPrice;
    }

    /**
     * Function which calculates discounts on order level and where percentage
     * takes over precedence over flat amount.
     *
     * @param       $discount
     * @param float $noDeductiblesCost
     *
     * @return float|int
     */
    private static function _calculateOrderDiscounts($discount, float $noDeductiblesCost): float|int
    {
        return ($discount->percentage) ? ($noDeductiblesCost * $discount->percentage / 100) :
            $discount->amount;
    }

    /**
     * Function which calculates discounts on product level and where percentage
     * takes over precedence over flat amount.
     *
     * @param $products
     * @param $discount
     *
     * @return float|int
     */
    private static function _calculateProductDiscounts($products, $discount): float|int
    {
        $product = $products->first(function ($product) use ($discount) {
            return $product->pivot->discounts->contains($discount) || $product->discounts->contains($discount);
        });

        if ($product) {
            return ($discount->percentage) ? ($product->pivot->base_price_money_amount * $product->pivot->quantity * $discount->percentage / 100) :
                $discount->amount;
        } else {
            return 0;
        }
    }

    /**
     * Function which calculates taxes on product level.
     *
     * @param            $products
     * @param            $tax
     * @param Collection $inclusiveTaxes
     * @param Collection $discounts
     *
     * @return float|int
     */
    private static function _calculateProductTaxes($products, $tax, Collection $inclusiveTaxes, Collection $discounts): float|int
    {
        $product = $products->first(function ($product) use ($tax) {
            return $product->pivot->taxes->contains($tax) || $product->taxes->contains($tax);
        });

        if ($product) {
            // Get the total product cost (price * quantity)
            $totalCost = $product->pivot->base_price_money_amount * $product->pivot->quantity;

            // Calculate order discounts as this will impact the taxes calculated
            $discountCost = $totalCost - self::_calculateDiscounts($discounts, $totalCost, $products);

            $netPrice = self::_calculateNetPrice($discountCost, $inclusiveTaxes);

            // Calculate and round the product taxes
            $productTaxes = $netPrice * ($tax->percentage / 100);

            return self::_roundMoney($productTaxes);
        } else {
            return 0;
        }
    }

    /**
     * Function which calculates taxes on order level.
     *
     * @param float      $discountCost
     * @param            $tax
     * @param Collection $inclusiveTaxes
     *
     * @return float|int
     */
    private static function _calculateOrderTaxes(float $discountCost, $tax, Collection $inclusiveTaxes): float|int
    {
        // Calculate the net price (amount without inclusive tax)
        $netPrice = self::_calculateNetPrice($discountCost, $inclusiveTaxes);

        // Get the order taxes
        $orderTaxes = $netPrice * $tax->percentage / 100;

        return self::_roundMoney($orderTaxes);
    }

    /**
     * Function which calculates service charges on order level and where percentage
     * takes over precedence over flat amount.
     *
     * @param       $serviceCharge
     * @param float $amount
     *
     * @return float|int
     */
    private static function _calculateOrderServiceCharges($serviceCharge, float $amount): float|int
    {
        return ($serviceCharge->percentage) ? self::_roundMoney($amount * $serviceCharge->percentage / 100) :
            $serviceCharge->amount_money;
    }

    /**
     * Function which calculates service charges on product level and where percentage
     * takes over precedence over flat amount.
     *
     * @param $products
     * @param $serviceCharge
     *
     * @return float|int
     */
    private static function _calculateProductServiceCharges($products, $serviceCharge): float|int
    {
        // Handle apportioned service charges efficiently
        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE) {
            throw new Exception('Service charge calculation phase "SUBTOTAL" cannot be applied to products in an order.');
        }

        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE) {
            // Apply fixed amount per line item quantity
            $totalQuantity = $products->sum('pivot.quantity');

            return $serviceCharge->amount_money * $totalQuantity;
        }

        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE) {
            // Apply percentage to total product value - use cached calculation if available
            $totalValue = $products->sum(function ($product) {
                return $product->pivot->base_price_money_amount * $product->pivot->quantity;
            });

            return $totalValue * $serviceCharge->percentage / 100;
        }

        // For non-apportioned service charges, find the specific product efficiently
        $targetProduct = $products->first(function ($product) use ($serviceCharge) {
            return $product->pivot->serviceCharges->contains($serviceCharge);
        });

        if (!$targetProduct) {
            return 0;
        }

        $pivot = $targetProduct->pivot;

        return $serviceCharge->percentage ?
            ($pivot->base_price_money_amount * $pivot->quantity * $serviceCharge->percentage / 100) :
            $serviceCharge->amount_money;
    }

    /**
     * Calculate all service charges on order level no matter
     * their scope.
     *
     * @param Collection $serviceCharges
     * @param float      $baseAmount
     * @param Collection $products
     *
     * @return float|int
     */
    private static function _calculateServiceCharges(Collection $serviceCharges, float $baseAmount, Collection $products): float|int
    {
        if ($serviceCharges->isEmpty() || $products->isEmpty()) {
            return 0;
        }

        return $serviceCharges->sum(function ($serviceCharge) use ($products, $baseAmount) {
            $scope = $serviceCharge->pivot ? $serviceCharge->pivot->scope : $serviceCharge->scope;

            return match ($scope) {
                Constants::DEDUCTIBLE_SCOPE_PRODUCT => self::_calculateProductServiceCharges($products, $serviceCharge),
                Constants::DEDUCTIBLE_SCOPE_ORDER   => self::_calculateOrderServiceCharges($serviceCharge, $baseAmount),
                default                             => 0
            };
        });
    }

    /**
     * Calculate taxes on service charges based on their treatment type.
     *
     * @param Collection $serviceCharges
     * @param Collection $products
     *
     * @return float|int
     */
    private static function _calculateServiceChargeTaxes(Collection $serviceCharges, Collection $products): float|int
    {
        if ($serviceCharges->isEmpty()) {
            return 0;
        }

        return $serviceCharges->sum(function ($serviceCharge) use ($products) {
            // Apportioned service charges inherit taxes from line items - no direct taxes
            if (
                $serviceCharge->treatment_type === OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT
                || $serviceCharge->taxable === false
            ) {
                return 0;
            }

            // Skip if no taxes are associated with this service charge
            $serviceChargeTaxes = $serviceCharge->taxes ?? collect([]);
            if ($serviceChargeTaxes->isEmpty()) {
                return 0;
            }

            // Calculate the service charge amount efficiently
            $scope = $serviceCharge->pivot ? $serviceCharge->pivot->scope : $serviceCharge->scope;
            $serviceChargeAmount = match ($scope) {
                Constants::DEDUCTIBLE_SCOPE_PRODUCT => self::_calculateProductServiceCharges($products, $serviceCharge),
                Constants::DEDUCTIBLE_SCOPE_ORDER   => $serviceCharge->percentage ?
                    self::_roundMoney($serviceCharge->percentage / 100 * self::_getOrderBaseAmount($products)) :
                    $serviceCharge->amount_money,
                default => 0
            };

            // Apply taxes to the service charge amount
            return $serviceChargeTaxes->sum(function ($tax) use ($serviceChargeAmount) {
                return self::_roundMoney($serviceChargeAmount * $tax->percentage / 100);
            });
        });
    }

    /**
     * Collects all the service charges from products and order and combines them.
     *
     * @param Model|stdClass $order
     *
     * @return Collection
     */
    public static function collectServiceCharges(Model|stdClass $order): Collection
    {
        // Collect service charges from order level (with taxes)
        $orderServiceCharges = $order instanceof Model
            ? $order->serviceCharges()->with('taxes')->get() ?? collect([])
            : $order->serviceCharges ?? collect([]);

        // Collect service charges from product pivots (with taxes)
        $productServiceCharges = collect([]);
        if ($order->products && $order->products->isNotEmpty()) {
            $productServiceCharges = $order->products->flatMap(function ($product) {
                return $product instanceof Model
                    ? $product->pivot->serviceCharges()->with('taxes')->get() ?? collect([])
                    : $product->pivot->serviceCharges ?? collect([]);
            });
        }

        // Merge all service charges
        return $orderServiceCharges->merge($productServiceCharges);
    }

    /**
     * Get the base order amount for service charge calculations.
     *
     * @param Collection $products
     *
     * @return float|int
     */
    private static function _getOrderBaseAmount(Collection $products): float|int
    {
        return $products->sum(function ($product) {
            $pivot = $product->pivot;
            $productPrice = $pivot->base_price_money_amount;

            // Add modifier costs efficiently
            if ($pivot->modifiers->isNotEmpty()) {
                $productPrice += $pivot->modifiers->sum(function ($modifier) {
                    return $modifier->modifiable?->price_money_amount ?? 0;
                });
            }

            return $productPrice * $pivot->quantity;
        });
    }

    /**
     * Calculate all additive taxes on order level.
     * Inclusive taxes are not added to the cost as they're already included in the price.
     *
     * @param Collection $taxes
     * @param float      $discountCost
     * @param Collection $products
     *
     * @return float|int
     */
    private static function _calculateAdditiveTaxes(Collection $taxes, float $discountCost, Collection $products, Collection $discounts): float|int
    {
        if ($taxes->isEmpty() || $products->isEmpty()) {
            return 0;
        }

        // Pre-filter taxes for efficiency
        $additiveTaxes = $taxes->filter(fn ($tax) => $tax->type === Constants::TAX_ADDITIVE);
        $inclusiveTaxes = $taxes->filter(fn ($tax) => $tax->type === Constants::TAX_INCLUSIVE);

        if ($additiveTaxes->isEmpty()) {
            return 0;
        }

        return $additiveTaxes->sum(function ($tax) use ($products, $discountCost, $discounts, $inclusiveTaxes) {
            $scope = $tax->pivot ? $tax->pivot->scope : $tax->scope;

            return match ($scope) {
                Constants::DEDUCTIBLE_SCOPE_PRODUCT => self::_calculateProductTaxes($products, $tax, $inclusiveTaxes, $discounts),
                Constants::DEDUCTIBLE_SCOPE_ORDER   => self::_calculateOrderTaxes($discountCost, $tax, $inclusiveTaxes),
                default                             => 0
            };
        });
    }

    /**
     * Calculate total order cost.
     *
     * @param Collection $discounts
     * @param Collection $taxes
     * @param Collection $serviceCharges
     * @param Collection $products
     *
     * @return float|int
     */
    private static function _calculateTotalCost(Collection $discounts, Collection $taxes, Collection $serviceCharges, Collection $products): float|int
    {
        // Early validation
        if ($products->isEmpty()) {
            throw new Exception('Total cost cannot be calculated without products.');
        }

        // Pre-filter all collections by scope once for efficiency
        $allDiscounts = self::_mergeCollectionsByScope($discounts);
        $allTaxes = self::_mergeCollectionsByScope($taxes);
        $allServiceCharges = self::_mergeCollectionsByScope($serviceCharges);

        // Separate taxes by calculation phase
        $subtotalPhaseTaxes = $allTaxes->filter(function (Tax $tax) {
            return $tax->isCalculatedOnSubtotal();
        });

        $totalPhaseTaxes = $allTaxes->filter(function (Tax $tax) {
            return $tax->isCalculatedOnTotal();
        });

        // Separate service charges by calculation phase
        $subtotalServiceCharges = $allServiceCharges->filter(function ($serviceCharge) {
            return in_array($serviceCharge->calculation_phase, [
                OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE,
                OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE,
                OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE,
            ]);
        });

        $totalServiceCharges = $allServiceCharges->filter(function ($serviceCharge) {
            return $serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::TOTAL_PHASE;
        });

        // Cache product calculations - calculate base cost only once
        $productCalculations = self::_calculateProductTotals($products);
        $noDeductiblesCost = $productCalculations['baseCost'];

        // Apply discounts first to the subtotal
        $discountCost = $noDeductiblesCost - self::_calculateDiscounts($allDiscounts, $noDeductiblesCost, $products);

        // Add subtotal-phase service charges to discount cost
        $subTotalAmount = $discountCost + self::_calculateServiceCharges($subtotalServiceCharges, $discountCost, $products);

        // Apply subtotal-phase taxes (before total-phase service charges)
        $subtotalTaxedCost = $subTotalAmount + self::_calculateAdditiveTaxes($subtotalPhaseTaxes, $subTotalAmount, $products, $allDiscounts);

        // Add total-phase service charges after subtotal taxes
        $totalServiceChargeAmount = self::_calculateServiceCharges($totalServiceCharges, $subtotalTaxedCost, $products);
        $preTotal = $subtotalTaxedCost + $totalServiceChargeAmount;

        // Apply total-phase taxes (after service charges)
        $totalTaxedCost = $preTotal + self::_calculateAdditiveTaxes($totalPhaseTaxes, $preTotal, $products, $allDiscounts);

        // Finally, calculate service charge taxes
        $serviceChargeTaxAmount = self::_calculateServiceChargeTaxes($allServiceCharges, $products);

        return $totalTaxedCost + $serviceChargeTaxAmount;
    }

    /**
     * Efficiently merge collections by scope to avoid multiple filter operations.
     *
     * @param Collection $collection
     *
     * @return Collection
     */
    private static function _mergeCollectionsByScope(Collection $collection): Collection
    {
        if ($collection->isEmpty()) {
            return collect([]);
        }

        return $collection->filter(function ($obj) {
            $scope = $obj->pivot ? $obj->pivot->scope : $obj->scope;

            return in_array($scope, [Constants::DEDUCTIBLE_SCOPE_ORDER, Constants::DEDUCTIBLE_SCOPE_PRODUCT]);
        });
    }

    /**
     * Calculate product totals once and cache for reuse.
     *
     * @param Collection $products
     *
     * @return array
     */
    private static function _calculateProductTotals(Collection $products): array
    {
        $baseCost = 0;
        $productDetails = [];

        foreach ($products as $product) {
            $pivot = $product->pivot;
            $productPrice = $pivot->base_price_money_amount;

            // Calculate modifier cost once
            $modifierCost = 0;
            if ($pivot->modifiers->isNotEmpty()) {
                $modifierCost = $pivot->modifiers->sum(function ($modifier) {
                    return $modifier->modifiable?->price_money_amount ?? 0;
                });
            }

            $totalProductPrice = $productPrice + $modifierCost;
            $lineTotal = $totalProductPrice * $pivot->quantity;

            $baseCost += $lineTotal;
            $productDetails[] = [
                'product'      => $product,
                'basePrice'    => $productPrice,
                'modifierCost' => $modifierCost,
                'totalPrice'   => $totalProductPrice,
                'quantity'     => $pivot->quantity,
                'lineTotal'    => $lineTotal,
            ];
        }

        return [
            'baseCost'       => $baseCost,
            'productDetails' => $productDetails,
        ];
    }

    /**
     * Calculates order total based on Model.
     *
     * @param Model $order
     *
     * @return float|int
     */
    public static function calculateTotalOrderCostByModel(Model $order): float|int
    {
        $order->loadMissing(['lineItems']);

        if ($order->lineItems->count() > 1) {
            return $order->lineItems->sum(
                fn (OrderProductPivot $lineItem) => self::calculateLineItemTotalByModel($lineItem, $order)
            );
        }

        $allServiceCharges = self::collectServiceCharges($order);

        return self::_calculateTotalCost($order->discounts, $order->taxes, $allServiceCharges, $order->products);
    }

    /**
     * Calculate the total cost for a single line item, including its apportioned
     * share of order-level taxes, discounts, and service charges.
     *
     * Mirrors Square's per-line-item total calculation: order-level adjustments
     * are apportioned proportionally by gross sales ratio.
     *
     * @param OrderProductPivot $lineItem The line item to calculate.
     * @param Model             $order    The parent order (for order-level deductibles).
     *
     * @return float|int The total cost for this line item in cents.
     */
    public static function calculateLineItemTotalByModel(OrderProductPivot $lineItem, Model $order): float|int
    {
        $lineItem->loadMissing(['taxes', 'discounts', 'serviceCharges', 'modifiers.modifiable']);
        $order->loadMissing(['lineItems.modifiers.modifiable', 'lineItems.serviceCharges', 'discounts', 'taxes']);

        $allServiceCharges = self::collectServiceCharges($order);
        $allLineItems = $order->lineItems;

        return self::_calculateLineItemTotal($lineItem, $order->discounts, $order->taxes, $allServiceCharges, $allLineItems);
    }

    /**
     * Core calculation pipeline for a single line item.
     *
     * Follows the same sequence as _calculateTotalCost:
     * base cost → discounts → subtotal service charges → subtotal taxes
     * → total service charges → total taxes → service charge taxes
     *
     * ORDER-scoped deductibles are apportioned by gross sales ratio.
     *
     * @param OrderProductPivot $lineItem
     * @param Collection        $orderDiscounts
     * @param Collection        $orderTaxes
     * @param Collection        $orderServiceCharges
     * @param Collection        $allLineItems
     *
     * @return float|int
     */
    private static function _calculateLineItemTotal(
        OrderProductPivot $lineItem,
        Collection $orderDiscounts,
        Collection $orderTaxes,
        Collection $orderServiceCharges,
        Collection $allLineItems
    ): float|int {
        // Step 1: Base cost for this line item
        $lineItemBaseCost = self::_calculateLineItemBaseCost($lineItem);

        // Step 2: Apportionment ratio (this line item's share of order gross sales)
        $orderBaseCost = self::_calculateAllLineItemsBaseCost($allLineItems);
        $ratio = ($orderBaseCost > 0) ? $lineItemBaseCost / $orderBaseCost : 0;

        // Step 3: Collect all applicable deductibles for this line item
        $isCustomLineItem = is_null($lineItem->product_id);

        // Line-item-scoped deductibles (directly attached to this line item)
        $lineItemDiscounts = self::_mergeCollectionsByScope($lineItem->discounts ?? collect([]));
        $lineItemTaxes = self::_mergeCollectionsByScope($lineItem->taxes ?? collect([]));
        $lineItemServiceCharges = self::_mergeCollectionsByScope($lineItem->serviceCharges ?? collect([]));

        // Order-scoped deductibles
        $orderScopedDiscounts = self::_filterOrderScoped($orderDiscounts);
        $orderScopedTaxes = self::_filterOrderScoped($orderTaxes);

        // For custom line items, only include ORDER taxes that apply to custom amounts
        if ($isCustomLineItem) {
            $orderScopedTaxes = $orderScopedTaxes->filter(
                fn (Tax $tax) => $tax->appliesToCustomAmounts()
            );
        }

        $orderScopedServiceCharges = self::_filterOrderScoped($orderServiceCharges);

        // Merge all applicable deductibles
        $allDiscounts = $lineItemDiscounts->merge($orderScopedDiscounts);
        $allTaxes = $lineItemTaxes->merge($orderScopedTaxes);
        $allServiceCharges = $lineItemServiceCharges->merge($orderScopedServiceCharges);

        // Step 4: Separate taxes by calculation phase
        $subtotalPhaseTaxes = $allTaxes->filter(fn (Tax $tax) => $tax->isCalculatedOnSubtotal());
        $totalPhaseTaxes = $allTaxes->filter(fn (Tax $tax) => $tax->isCalculatedOnTotal());

        // Step 5: Separate service charges by calculation phase
        $subtotalServiceCharges = $allServiceCharges->filter(fn ($sc) => in_array($sc->calculation_phase, [
            OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE,
            OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE,
            OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE,
        ]));
        $totalServiceCharges = $allServiceCharges->filter(
            fn ($sc) => $sc->calculation_phase === OrderServiceChargeCalculationPhase::TOTAL_PHASE
        );

        // Step 6: Apply discounts
        $discountAmount = self::_calculateLineItemDiscounts($allDiscounts, $lineItemBaseCost, $ratio);
        $discountedCost = $lineItemBaseCost - $discountAmount;

        // Step 7: Add subtotal-phase service charges
        $subtotalServiceChargeBreakdown = self::_calculateLineItemServiceChargeBreakdown(
            $subtotalServiceCharges,
            $discountedCost,
            $lineItem,
            $allLineItems,
            $ratio
        );
        $subtotalSCAmount = $subtotalServiceChargeBreakdown->sum('amount');
        $subtotalAmount = $discountedCost + $subtotalSCAmount;

        // Step 8: Add subtotal-phase taxes
        $subtotalTaxAmount = self::_calculateLineItemTaxes($subtotalPhaseTaxes, $subtotalAmount);
        $subtotalTaxedCost = $subtotalAmount + $subtotalTaxAmount;

        // Step 9: Add total-phase service charges
        $totalServiceChargeBreakdown = self::_calculateLineItemServiceChargeBreakdown(
            $totalServiceCharges,
            $subtotalTaxedCost,
            $lineItem,
            $allLineItems,
            $ratio
        );
        $totalSCAmount = $totalServiceChargeBreakdown->sum('amount');
        $preTotal = $subtotalTaxedCost + $totalSCAmount;

        // Step 10: Add total-phase taxes
        $totalTaxAmount = self::_calculateLineItemTaxes($totalPhaseTaxes, $preTotal);
        $totalTaxedCost = $preTotal + $totalTaxAmount;

        // Step 11: Add service charge taxes
        $scTaxAmount = self::_calculateLineItemServiceChargeTaxes(
            $subtotalServiceChargeBreakdown->merge($totalServiceChargeBreakdown)
        );

        return $totalTaxedCost + $scTaxAmount;
    }

    /**
     * Calculate base cost for a single line item: (base_price + modifiers) × quantity.
     *
     * @param OrderProductPivot $lineItem
     *
     * @return float|int
     */
    private static function _calculateLineItemBaseCost(OrderProductPivot $lineItem): float|int
    {
        $basePrice = $lineItem->base_price_money_amount ?? 0;

        $modifierCost = 0;
        if ($lineItem->modifiers && $lineItem->modifiers->isNotEmpty()) {
            $modifierCost = $lineItem->modifiers->sum(
                fn ($modifier) => $modifier->modifiable?->price_money_amount ?? 0
            );
        }

        return ($basePrice + $modifierCost) * ($lineItem->quantity ?? 1);
    }

    /**
     * Sum base costs across all line items for the apportionment denominator.
     *
     * @param Collection $lineItems Collection of OrderProductPivot
     *
     * @return float|int
     */
    private static function _calculateAllLineItemsBaseCost(Collection $lineItems): float|int
    {
        return $lineItems->sum(fn (OrderProductPivot $li) => self::_calculateLineItemBaseCost($li));
    }

    /**
     * Filter a collection of deductibles to only ORDER-scoped items.
     *
     * @param Collection $deductibles
     *
     * @return Collection
     */
    private static function _filterOrderScoped(Collection $deductibles): Collection
    {
        return $deductibles->filter(function ($item) {
            $scope = $item->pivot ? $item->pivot->scope : $item->scope;

            return $scope === Constants::DEDUCTIBLE_SCOPE_ORDER;
        });
    }

    /**
     * Calculate discounts for a single line item.
     *
     * Percentage discounts apply their percentage to this line item's base cost.
     * Fixed-amount ORDER-scoped discounts are apportioned by gross sales ratio.
     * Fixed-amount LINE_ITEM-scoped discounts apply their full amount.
     *
     * @param Collection $discounts
     * @param float|int  $lineItemBaseCost
     * @param float      $ratio            Apportionment ratio for ORDER-scoped fixed amounts
     *
     * @return float|int Total discount amount
     */
    private static function _calculateLineItemDiscounts(Collection $discounts, float|int $lineItemBaseCost, float $ratio): float|int
    {
        if ($discounts->isEmpty()) {
            return 0;
        }

        $runningAmount = $lineItemBaseCost;
        $totalDiscount = 0;

        $discountGroups = [
            fn ($discount) => ($discount->pivot ? $discount->pivot->scope : $discount->scope) === Constants::DEDUCTIBLE_SCOPE_PRODUCT && $discount->percentage,
            fn ($discount) => ($discount->pivot ? $discount->pivot->scope : $discount->scope) === Constants::DEDUCTIBLE_SCOPE_ORDER && $discount->percentage,
            fn ($discount) => ($discount->pivot ? $discount->pivot->scope : $discount->scope) === Constants::DEDUCTIBLE_SCOPE_PRODUCT && !$discount->percentage,
            fn ($discount) => ($discount->pivot ? $discount->pivot->scope : $discount->scope) === Constants::DEDUCTIBLE_SCOPE_ORDER && !$discount->percentage,
        ];

        foreach ($discountGroups as $groupFilter) {
            foreach ($discounts->filter($groupFilter) as $discount) {
                $scope = $discount->pivot ? $discount->pivot->scope : $discount->scope;
                $discountAmount = $discount->percentage
                    ? self::_roundMoney($runningAmount * $discount->percentage / 100)
                    : ($scope === Constants::DEDUCTIBLE_SCOPE_ORDER
                        ? self::_roundMoney(($discount->amount ?? 0) * $ratio)
                        : ($discount->amount ?? 0));

                $discountAmount = min($discountAmount, $runningAmount);
                $totalDiscount += $discountAmount;
                $runningAmount -= $discountAmount;
            }
        }

        return $totalDiscount;
    }

    /**
     * Calculate additive taxes for a single line item.
     *
     * Inclusive taxes reduce the net price base; additive taxes are added on top.
     *
     * @param Collection $taxes
     * @param float|int  $baseAmount The amount to apply taxes to
     *
     * @return float|int Total tax amount
     */
    private static function _calculateLineItemTaxes(Collection $taxes, float|int $baseAmount): float|int
    {
        if ($taxes->isEmpty()) {
            return 0;
        }

        $additiveTaxes = $taxes->filter(fn ($tax) => $tax->type === Constants::TAX_ADDITIVE);
        $inclusiveTaxes = $taxes->filter(fn ($tax) => $tax->type === Constants::TAX_INCLUSIVE);

        if ($additiveTaxes->isEmpty()) {
            return 0;
        }

        // Calculate net price by removing inclusive taxes
        $netPrice = self::_calculateNetPrice($baseAmount, $inclusiveTaxes);

        return (int) $additiveTaxes->sum(
            fn ($tax) => round($netPrice * $tax->percentage / 100)
        );
    }

    /**
     * Calculate service charges for a single line item.
     *
     * ORDER-scoped percentage charges apply to this line item's base amount.
     * ORDER-scoped fixed charges are apportioned by gross sales ratio.
     * APPORTIONED_AMOUNT charges use amount × lineItem quantity.
     * APPORTIONED_PERCENTAGE charges use percentage × base amount.
     * LINE_ITEM-scoped charges apply directly.
     *
     * @param Collection        $serviceCharges
     * @param float|int         $baseAmount     Current line item subtotal
     * @param OrderProductPivot $lineItem
     * @param float             $ratio          Apportionment ratio
     *
     * @return float|int Total service charge amount
     */
    private static function _calculateLineItemServiceChargeBreakdown(
        Collection $serviceCharges,
        float|int $baseAmount,
        OrderProductPivot $lineItem,
        Collection $allLineItems,
        float $ratio
    ): Collection {
        if ($serviceCharges->isEmpty()) {
            return collect([]);
        }

        return $serviceCharges->map(function ($sc) use ($baseAmount, $lineItem, $allLineItems, $ratio) {
            return [
                'service_charge' => $sc,
                'amount'         => self::_calculateLineItemServiceChargeAmount($sc, $baseAmount, $lineItem, $allLineItems, $ratio),
            ];
        });
    }

    /**
     * Calculate taxes on service charges for a single line item.
     *
     * Only applies to non-APPORTIONED_TREATMENT, taxable service charges.
     * The service charge amount attributable to this line item is taxed.
     *
     * @param Collection        $serviceCharges
     * @param OrderProductPivot $lineItem
     * @param float             $ratio          Apportionment ratio
     *
     * @return float|int
     */
    private static function _calculateLineItemServiceChargeTaxes(Collection $serviceChargeBreakdown): float|int
    {
        if ($serviceChargeBreakdown->isEmpty()) {
            return 0;
        }

        return $serviceChargeBreakdown->sum(function (array $serviceChargeData) {
            /** @var mixed $sc */
            $sc = $serviceChargeData['service_charge'];
            $scAmount = $serviceChargeData['amount'];

            // Apportioned service charges inherit taxes from line items — no direct taxes
            if (
                $sc->treatment_type === OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT
                || $sc->taxable === false
            ) {
                return 0;
            }

            $scTaxes = $sc->taxes ?? collect([]);
            if ($scTaxes->isEmpty()) {
                return 0;
            }

            // Apply each tax to the service charge amount
            return $scTaxes->sum(fn ($tax) => self::_roundMoney($scAmount * $tax->percentage / 100));
        });
    }

    /**
     * Calculate a single service charge amount attributable to one line item.
     *
     * @param mixed             $serviceCharge
     * @param float|int         $baseAmount
     * @param OrderProductPivot $lineItem
     * @param Collection        $allLineItems
     * @param float             $ratio
     *
     * @return int
     */
    private static function _calculateLineItemServiceChargeAmount(
        $serviceCharge,
        float|int $baseAmount,
        OrderProductPivot $lineItem,
        Collection $allLineItems,
        float $ratio
    ): int {
        $scope = $serviceCharge->pivot ? $serviceCharge->pivot->scope : $serviceCharge->scope;

        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE) {
            $apportionmentRatio = self::_calculateLineItemServiceChargeRatio($serviceCharge, $lineItem, $allLineItems, $ratio);

            return self::_roundMoney(($serviceCharge->amount_money ?? 0) * $apportionmentRatio);
        }

        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE) {
            return self::_roundMoney($baseAmount * ($serviceCharge->percentage ?? 0) / 100);
        }

        if ($scope === Constants::DEDUCTIBLE_SCOPE_ORDER) {
            if ($serviceCharge->percentage) {
                return self::_roundMoney($baseAmount * $serviceCharge->percentage / 100);
            }

            return self::_roundMoney(($serviceCharge->amount_money ?? 0) * $ratio);
        }

        if ($serviceCharge->percentage) {
            return self::_roundMoney($baseAmount * $serviceCharge->percentage / 100);
        }

        return (int) ($serviceCharge->amount_money ?? 0);
    }

    /**
     * Calculate the applicable apportionment ratio for a service charge.
     *
     * @param mixed             $serviceCharge
     * @param OrderProductPivot $lineItem
     * @param Collection        $allLineItems
     * @param float             $defaultRatio
     *
     * @return float
     */
    private static function _calculateLineItemServiceChargeRatio(
        $serviceCharge,
        OrderProductPivot $lineItem,
        Collection $allLineItems,
        float $defaultRatio
    ): float {
        $scope = $serviceCharge->pivot ? $serviceCharge->pivot->scope : $serviceCharge->scope;

        if ($scope === Constants::DEDUCTIBLE_SCOPE_ORDER) {
            return $defaultRatio;
        }

        $applicableLineItems = $allLineItems->filter(function (OrderProductPivot $candidate) use ($serviceCharge) {
            $candidate->loadMissing('serviceCharges');

            return $candidate->serviceCharges->contains(fn ($attachedServiceCharge) => $attachedServiceCharge->id === $serviceCharge->id);
        });

        if ($applicableLineItems->isEmpty()) {
            return 0;
        }

        $applicableBaseCost = self::_calculateAllLineItemsBaseCost($applicableLineItems);
        if ($applicableBaseCost <= 0) {
            return 0;
        }

        return self::_calculateLineItemBaseCost($lineItem) / $applicableBaseCost;
    }

    /**
     * Round monetary adjustments using Square's documented bankers' rounding.
     *
     * @param float|int $amount
     *
     * @return int
     */
    private static function _roundMoney(float|int $amount): int
    {
        return (int) round($amount, 0, PHP_ROUND_HALF_EVEN);
    }

    /**
     * Check if source has fulfillment.
     *
     * @param stdClass $order
     *
     * @return bool
     */
    public static function hasFulfillment(
        \Illuminate\Database\Eloquent\Collection|Collection $source,
        Fulfillment|int|array|null $fulfillment
    ): bool {
        // Check if $fulfillment is either int, Model or array
        if (is_a($fulfillment, Fulfillment::class)) {
            return $source->contains($fulfillment);
        } elseif (is_array($fulfillment)) {
            if (array_key_exists('id', $fulfillment)) {
                return $source->contains(Fulfillment::find($fulfillment['id']));
            } elseif (array_key_exists('name', $fulfillment)) {
                return $source->contains(Fulfillment::where('name', $fulfillment['name'])->first());
            }
        } elseif (is_int($fulfillment)) {
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
        // Check if $product is either int, Model or array
        if (is_a($product, Product::class)) {
            return $source->contains($product);
        } elseif (is_array($product)) {
            if (array_key_exists('id', $product)) {
                return $source->contains(Product::find($product['id']));
            } elseif (array_key_exists('name', $product)) {
                return $source->contains(Product::where('name', $product['name'])->first());
            }
        } elseif (is_int($product)) {
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
