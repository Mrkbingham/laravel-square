<?php

namespace Nikolag\Square\Utils;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nikolag\Square\Dto\OrderTotalsBreakdown;
use Nikolag\Square\Models\OrderProductPivot;
use Nikolag\Square\Models\Tax;
use Square\Models\OrderServiceChargeCalculationPhase;
use Square\Models\OrderServiceChargeTreatmentType;
use stdClass;

class OrderCalculator
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
            $context = self::_buildOrderContext($order);

            return $context['allLineItems']->sum(
                fn (OrderProductPivot $lineItem) => self::_calculateLineItemBreakdownWithContext($lineItem, $context)['total']
            );
        }

        $allServiceCharges = self::collectServiceCharges($order);

        return self::_calculateTotalCost($order->discounts, $order->taxes, $allServiceCharges, $order->products);
    }

    /**
     * Calculate the total cost for a single line item, including its apportioned
     * share of order-level taxes, discounts, and service charges.
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

        return self::_calculateLineItemBreakdown($lineItem, $order->discounts, $order->taxes, $allServiceCharges, $allLineItems)['total'];
    }

    /**
     * Calculate the full order totals breakdown in a single pass.
     *
     * Returns a DTO containing net amount, total amount, total tax,
     * total discount, and total service charge — all as integer cents.
     *
     * @param Model $order
     *
     * @return OrderTotalsBreakdown
     */
    public static function calculateOrderTotalsBreakdown(Model $order): OrderTotalsBreakdown
    {
        $order->loadMissing(['lineItems']);

        if ($order->lineItems->count() > 1) {
            $context = self::_buildOrderContext($order);

            $breakdowns = $context['allLineItems']->map(
                fn (OrderProductPivot $lineItem) => self::_calculateLineItemBreakdownWithContext($lineItem, $context)
            );
        } else {
            // Single line item: use the line-item pipeline for consistent breakdown
            $order->loadMissing([
                'lineItems.modifiers.modifiable',
                'lineItems.serviceCharges.taxes',
                'lineItems.taxes',
                'lineItems.discounts',
                'discounts',
                'taxes',
            ]);

            $allServiceCharges = self::collectServiceCharges($order);
            $allLineItems = $order->lineItems;

            $breakdowns = $allLineItems->map(
                fn (OrderProductPivot $lineItem) => self::_calculateLineItemBreakdown(
                    $lineItem, $order->discounts, $order->taxes, $allServiceCharges, $allLineItems
                )
            );
        }

        return new OrderTotalsBreakdown(
            netAmount: (int) $breakdowns->sum('baseCost'),
            totalAmount: (int) $breakdowns->sum('total'),
            totalTaxAmount: (int) $breakdowns->sum('taxAmount'),
            totalDiscountAmount: (int) $breakdowns->sum('discountAmount'),
            totalServiceChargeAmount: (int) $breakdowns->sum('serviceChargeAmount'),
        );
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
            ? $order->serviceCharges()->with('taxes')->get()
            : $order->serviceCharges ?? collect([]);

        // Collect service charges from product pivots (with taxes)
        $productServiceCharges = collect([]);
        if ($order->products && $order->products->isNotEmpty()) {
            $productServiceCharges = $order->products->flatMap(
                fn ($product) => $product instanceof Model
                    ? $product->pivot->serviceCharges()->with('taxes')->get()
                    : $product->pivot->serviceCharges ?? collect([])
            );
        }

        // Merge all service charges
        return $orderServiceCharges->merge($productServiceCharges);
    }

    /**
     * Build pre-computed order context for multi-line-item calculations.
     *
     * @param Model $order
     *
     * @return array
     */
    private static function _buildOrderContext(Model $order): array
    {
        $order->loadMissing([
            'lineItems.modifiers.modifiable',
            'lineItems.serviceCharges.taxes',
            'lineItems.taxes',
            'lineItems.discounts',
            'discounts',
            'taxes',
        ]);

        $allServiceCharges = self::collectServiceCharges($order);
        $allLineItems = $order->lineItems;

        $orderBaseCost = self::_calculateAllLineItemsBaseCost($allLineItems);
        $orderScopedDiscounts = self::_filterOrderScoped($order->discounts);
        $orderScopedTaxes = self::_filterOrderScoped($order->taxes);
        $orderScopedServiceCharges = self::_filterOrderScoped($allServiceCharges);

        $serviceChargeApplicableBaseCosts = self::_buildServiceChargeApplicableBaseCosts($allServiceCharges, $allLineItems);

        return [
            'allLineItems'                    => $allLineItems,
            'orderBaseCost'                    => $orderBaseCost,
            'orderScopedDiscounts'             => $orderScopedDiscounts,
            'orderScopedTaxes'                 => $orderScopedTaxes,
            'orderScopedServiceCharges'        => $orderScopedServiceCharges,
            'serviceChargeApplicableBaseCosts' => $serviceChargeApplicableBaseCosts,
        ];
    }

    /**
     * Calculate all discounts on order level no matter their scope.
     *
     * @param Collection $discounts
     * @param float      $noDeductiblesCost
     * @param Collection $products
     * @param array      $discountToProduct
     *
     * @return float|int
     */
    private static function _calculateDiscounts(Collection $discounts, float $noDeductiblesCost, Collection $products, array $discountToProduct = []): float|int
    {
        if ($discounts->isEmpty() || $products->isEmpty()) {
            return 0;
        }

        // Build index on-demand if not provided
        if (empty($discountToProduct)) {
            $discountToProduct = self::_buildDeductibleToProductIndex($products, 'discounts');
        }

        return $discounts->sum(function ($discount) use ($noDeductiblesCost, $discountToProduct) {
            return match (self::_getScope($discount)) {
                Constants::DEDUCTIBLE_SCOPE_PRODUCT => self::_calculateProductDiscounts($discount, $discountToProduct),
                Constants::DEDUCTIBLE_SCOPE_ORDER   => self::_calculateOrderDiscounts($discount, $noDeductiblesCost),
                default                             => 0
            };
        });
    }

    /**
     * Function which calculates the net price by removing any additive taxes to the entire order.
     *
     * @param float      $discountCost
     * @param Collection $inclusiveTaxes
     *
     * @return float|int
     */
    private static function _calculateNetPrice(float $discountCost, Collection $inclusiveTaxes): float|int
    {
        $inclusiveTaxPercent = $inclusiveTaxes->sum('percentage') / 100;

        return $discountCost / (1 + $inclusiveTaxPercent);
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
        return $discount->percentage
            ? self::_roundMoney($noDeductiblesCost * $discount->percentage / 100)
            : $discount->amount;
    }

    /**
     * Function which calculates discounts on product level and where percentage
     * takes over precedence over flat amount.
     *
     * @param $discount
     * @param array $discountToProduct
     *
     * @return float|int
     */
    private static function _calculateProductDiscounts($discount, array $discountToProduct): float|int
    {
        $product = $discountToProduct[$discount->id] ?? null;

        if (! $product) {
            return 0;
        }

        return $discount->percentage
            ? self::_roundMoney($product->pivot->base_price_money_amount * $product->pivot->quantity * $discount->percentage / 100)
            : $discount->amount;
    }

    /**
     * Function which calculates taxes on product level.
     *
     * @param            $tax
     * @param Collection $inclusiveTaxes
     * @param array      $taxToProduct
     * @param array      $productDiscountedCosts
     *
     * @return float|int
     */
    private static function _calculateProductTaxes($tax, Collection $inclusiveTaxes, array $taxToProduct, array $productDiscountedCosts): float|int
    {
        $product = $taxToProduct[$tax->id] ?? null;

        if (! $product) {
            return 0;
        }

        $productId = $product->pivot->id;
        $discountCost = $productDiscountedCosts[$productId] ?? ($product->pivot->base_price_money_amount * $product->pivot->quantity);

        $netPrice = self::_calculateNetPrice($discountCost, $inclusiveTaxes);

        return self::_roundMoney($netPrice * ($tax->percentage / 100));
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
        $netPrice = self::_calculateNetPrice($discountCost, $inclusiveTaxes);

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
        return $serviceCharge->percentage
            ? self::_roundMoney($amount * $serviceCharge->percentage / 100)
            : $serviceCharge->amount_money;
    }

    /**
     * Function which calculates service charges on product level and where percentage
     * takes over precedence over flat amount.
     *
     * @param Collection $products
     * @param            $serviceCharge
     * @param array      $serviceChargeToProduct
     *
     * @return float|int
     */
    private static function _calculateProductServiceCharges(Collection $products, $serviceCharge, array $serviceChargeToProduct = []): float|int
    {
        self::_assertNotSubtotalOnProduct(Constants::DEDUCTIBLE_SCOPE_PRODUCT, $serviceCharge->calculation_phase);

        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE) {
            $totalQuantity = $products->sum('pivot.quantity');

            return $serviceCharge->amount_money * $totalQuantity;
        }

        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE) {
            $totalValue = $products->sum(fn ($product) => $product->pivot->base_price_money_amount * $product->pivot->quantity);

            return $totalValue * $serviceCharge->percentage / 100;
        }

        // Use index for direct lookup if available, otherwise linear scan
        $targetProduct = !empty($serviceChargeToProduct)
            ? ($serviceChargeToProduct[$serviceCharge->id] ?? null)
            : $products->first(fn ($product) => $product->pivot->serviceCharges->contains($serviceCharge));

        if (!$targetProduct) {
            return 0;
        }

        $pivot = $targetProduct->pivot;

        return $serviceCharge->percentage ?
            ($pivot->base_price_money_amount * $pivot->quantity * $serviceCharge->percentage / 100) :
            $serviceCharge->amount_money;
    }

    /**
     * Calculate all service charges on order level no matter their scope.
     *
     * @param Collection $serviceCharges
     * @param float      $baseAmount
     * @param Collection $products
     * @param array      $serviceChargeToProduct
     *
     * @return float|int
     */
    private static function _calculateServiceCharges(Collection $serviceCharges, float $baseAmount, Collection $products, array $serviceChargeToProduct = []): float|int
    {
        if ($serviceCharges->isEmpty() || $products->isEmpty()) {
            return 0;
        }

        return $serviceCharges->sum(function ($serviceCharge) use ($products, $baseAmount, $serviceChargeToProduct) {
            return match (self::_getScope($serviceCharge)) {
                Constants::DEDUCTIBLE_SCOPE_PRODUCT => self::_calculateProductServiceCharges($products, $serviceCharge, $serviceChargeToProduct),
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
     * @param float|int  $orderBaseAmount
     * @param array      $serviceChargeToProduct
     *
     * @return float|int
     */
    private static function _calculateServiceChargeTaxes(Collection $serviceCharges, Collection $products, float|int $orderBaseAmount, array $serviceChargeToProduct = []): float|int
    {
        if ($serviceCharges->isEmpty()) {
            return 0;
        }

        return $serviceCharges->sum(function ($serviceCharge) use ($products, $orderBaseAmount, $serviceChargeToProduct) {
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

            // Calculate the service charge amount using pre-computed base amount
            $serviceChargeAmount = match (self::_getScope($serviceCharge)) {
                Constants::DEDUCTIBLE_SCOPE_PRODUCT => self::_calculateProductServiceCharges($products, $serviceCharge, $serviceChargeToProduct),
                Constants::DEDUCTIBLE_SCOPE_ORDER   => $serviceCharge->percentage ?
                    self::_roundMoney($serviceCharge->percentage / 100 * $orderBaseAmount) :
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
     * Calculate all additive taxes on order level.
     *
     * @param Collection $taxes
     * @param float      $discountCost
     * @param Collection $products
     * @param Collection $discounts
     * @param array      $taxToProduct
     * @param array      $discountToProduct
     *
     * @return float|int
     */
    private static function _calculateAdditiveTaxes(
        Collection $taxes,
        float $discountCost,
        Collection $products,
        Collection $discounts,
        array $taxToProduct = [],
        array $discountToProduct = []
    ): float|int {
        if ($taxes->isEmpty() || $products->isEmpty()) {
            return 0;
        }

        $additiveTaxes = $taxes->filter(fn ($tax) => $tax->type === Constants::TAX_ADDITIVE);
        $inclusiveTaxes = $taxes->filter(fn ($tax) => $tax->type === Constants::TAX_INCLUSIVE);

        if ($additiveTaxes->isEmpty()) {
            return 0;
        }

        // Build indexes on-demand if not provided
        if (empty($taxToProduct)) {
            $taxToProduct = self::_buildDeductibleToProductIndex($products, 'taxes');
        }
        if (empty($discountToProduct)) {
            $discountToProduct = self::_buildDeductibleToProductIndex($products, 'discounts');
        }

        // Pre-compute discounted cost per product once (avoids O(T*D*P))
        $productDiscountedCosts = [];
        foreach ($products as $product) {
            $productId = $product->pivot->id;
            $totalCost = $product->pivot->base_price_money_amount * $product->pivot->quantity;
            $productDiscountedCosts[$productId] = $totalCost - self::_calculateDiscounts($discounts, $totalCost, $products, $discountToProduct);
        }

        return $additiveTaxes->sum(function ($tax) use ($discountCost, $inclusiveTaxes, $taxToProduct, $productDiscountedCosts) {
            return match (self::_getScope($tax)) {
                Constants::DEDUCTIBLE_SCOPE_PRODUCT => self::_calculateProductTaxes($tax, $inclusiveTaxes, $taxToProduct, $productDiscountedCosts),
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
            throw new InvalidSquareOrderException('Total cost cannot be calculated without products.');
        }

        // Pre-filter all collections by scope once for efficiency
        $allDiscounts = self::_mergeCollectionsByScope($discounts);
        $allTaxes = self::_mergeCollectionsByScope($taxes);
        $allServiceCharges = self::_mergeCollectionsByScope($serviceCharges);

        // Separate taxes by calculation phase
        $subtotalPhaseTaxes = $allTaxes->filter(fn (Tax $tax) => $tax->isCalculatedOnSubtotal());
        $totalPhaseTaxes = $allTaxes->filter(fn (Tax $tax) => $tax->isCalculatedOnTotal());

        // Separate service charges by calculation phase
        $subtotalServiceCharges = $allServiceCharges->filter(fn ($sc) => in_array($sc->calculation_phase, [
            OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE,
            OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE,
            OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE,
        ]));
        $totalServiceCharges = $allServiceCharges->filter(
            fn ($sc) => $sc->calculation_phase === OrderServiceChargeCalculationPhase::TOTAL_PHASE
        );

        // Build reverse-index maps once for O(1) lookups
        $discountToProduct = self::_buildDeductibleToProductIndex($products, 'discounts');
        $taxToProduct = self::_buildDeductibleToProductIndex($products, 'taxes');
        $serviceChargeToProduct = self::_buildDeductibleToProductIndex($products, 'serviceCharges');

        // Calculate base cost only once
        $noDeductiblesCost = self::_calculateProductTotals($products);

        // Apply discounts first to the subtotal
        $discountCost = $noDeductiblesCost - self::_calculateDiscounts($allDiscounts, $noDeductiblesCost, $products, $discountToProduct);

        // Add subtotal-phase service charges to discount cost
        $subtotalSCAmount = self::_calculateServiceCharges($subtotalServiceCharges, $discountCost, $products, $serviceChargeToProduct);
        $taxableSubtotalSCAmount = self::_calculateServiceCharges(
            $subtotalServiceCharges->filter(fn ($sc) => $sc->taxable), $discountCost, $products, $serviceChargeToProduct
        );
        $subTotalAmount = $discountCost + $subtotalSCAmount;

        // Apply subtotal-phase taxes (only taxable service charges are included in the tax base)
        $subtotalTaxBase = $discountCost + $taxableSubtotalSCAmount;
        $subtotalTaxedCost = $subTotalAmount + self::_calculateAdditiveTaxes($subtotalPhaseTaxes, $subtotalTaxBase, $products, $allDiscounts, $taxToProduct, $discountToProduct);

        // Add total-phase service charges after subtotal taxes
        $totalServiceChargeAmount = self::_calculateServiceCharges($totalServiceCharges, $subtotalTaxedCost, $products, $serviceChargeToProduct);
        $taxableTotalSCAmount = self::_calculateServiceCharges(
            $totalServiceCharges->filter(fn ($sc) => $sc->taxable), $subtotalTaxedCost, $products, $serviceChargeToProduct
        );
        $preTotal = $subtotalTaxedCost + $totalServiceChargeAmount;

        // Apply total-phase taxes
        $totalTaxBase = $subtotalTaxBase + $taxableTotalSCAmount;
        $totalTaxedCost = $preTotal + self::_calculateAdditiveTaxes($totalPhaseTaxes, $totalTaxBase, $products, $allDiscounts, $taxToProduct, $discountToProduct);

        // Finally, calculate service charge taxes
        $serviceChargeTaxAmount = self::_calculateServiceChargeTaxes($allServiceCharges, $products, $noDeductiblesCost, $serviceChargeToProduct);

        return $totalTaxedCost + $serviceChargeTaxAmount;
    }

    /**
     * Build a reverse index mapping deductible IDs to their parent product.
     *
     * @param Collection $products
     * @param string     $relation 'discounts', 'taxes', or 'serviceCharges'
     *
     * @return array<int, mixed> Map of deductible ID to product
     */
    private static function _buildDeductibleToProductIndex(Collection $products, string $relation): array
    {
        $index = [];
        foreach ($products as $product) {
            $pivotItems = $product->pivot->$relation ?? collect([]);
            foreach ($pivotItems as $item) {
                $index[$item->id] = $product;
            }
            // Also check direct product-level deductibles
            $directItems = $product->$relation ?? collect([]);
            foreach ($directItems as $item) {
                if (!isset($index[$item->id])) {
                    $index[$item->id] = $product;
                }
            }
        }

        return $index;
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

        return $collection->filter(
            fn ($obj) => in_array(self::_getScope($obj), [Constants::DEDUCTIBLE_SCOPE_ORDER, Constants::DEDUCTIBLE_SCOPE_PRODUCT])
        );
    }

    /**
     * Calculate product totals once and cache for reuse.
     *
     * @param Collection $products
     *
     * @return float|int
     */
    private static function _calculateProductTotals(Collection $products): float|int
    {
        $baseCost = 0;

        foreach ($products as $product) {
            $pivot = $product->pivot;
            $productPrice = $pivot->base_price_money_amount;

            if ($pivot->modifiers->isNotEmpty()) {
                $productPrice += $pivot->modifiers->sum(
                    fn ($modifier) => $modifier->modifiable?->price_money_amount ?? 0
                );
            }

            $baseCost += $productPrice * $pivot->quantity;
        }

        return $baseCost;
    }

    /**
     * Core calculation pipeline for a single line item, returning a full breakdown.
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
     * @return array{baseCost: int, discountAmount: int, serviceChargeAmount: int, taxAmount: int, total: int}
     */
    private static function _calculateLineItemBreakdown(
        OrderProductPivot $lineItem,
        Collection $orderDiscounts,
        Collection $orderTaxes,
        Collection $orderServiceCharges,
        Collection $allLineItems
    ): array {
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
            $subtotalServiceCharges, $discountedCost, $lineItem, $allLineItems, $ratio
        );
        $subtotalSCAmount = $subtotalServiceChargeBreakdown->sum('amount');
        $taxableSubtotalSCAmount = $subtotalServiceChargeBreakdown
            ->filter(fn (array $entry) => $entry['service_charge']->taxable)
            ->sum('amount');
        $subtotalAmount = $discountedCost + $subtotalSCAmount;

        // Step 8: Add subtotal-phase taxes
        $subtotalTaxBase = $discountedCost + $taxableSubtotalSCAmount;
        $subtotalTaxAmount = self::_calculateLineItemTaxes($subtotalPhaseTaxes, $subtotalTaxBase);
        $subtotalTaxedCost = $subtotalAmount + $subtotalTaxAmount;

        // Step 9: Add total-phase service charges
        $totalServiceChargeBreakdown = self::_calculateLineItemServiceChargeBreakdown(
            $totalServiceCharges, $subtotalTaxedCost, $lineItem, $allLineItems, $ratio
        );
        $totalSCAmount = $totalServiceChargeBreakdown->sum('amount');
        $taxableTotalSCAmount = $totalServiceChargeBreakdown
            ->filter(fn (array $entry) => $entry['service_charge']->taxable)
            ->sum('amount');
        $preTotal = $subtotalTaxedCost + $totalSCAmount;

        // Step 10: Add total-phase taxes
        $totalTaxBase = $subtotalTaxBase + $taxableTotalSCAmount;
        $totalTaxAmount = self::_calculateLineItemTaxes($totalPhaseTaxes, $totalTaxBase);
        $totalTaxedCost = $preTotal + $totalTaxAmount;

        // Step 11: Add service charge taxes
        $scTaxAmount = self::_calculateLineItemServiceChargeTaxes(
            $subtotalServiceChargeBreakdown->merge($totalServiceChargeBreakdown)
        );

        return [
            'baseCost'            => $lineItemBaseCost,
            'discountAmount'      => $discountAmount,
            'serviceChargeAmount' => $subtotalSCAmount + $totalSCAmount,
            'taxAmount'           => $subtotalTaxAmount + $totalTaxAmount + $scTaxAmount,
            'total'               => $totalTaxedCost + $scTaxAmount,
        ];
    }

    /**
     * Calculate line item breakdown using pre-computed shared context.
     * Avoids recomputing order-level state for each line item.
     *
     * @param OrderProductPivot $lineItem
     * @param array             $context Pre-computed shared state
     *
     * @return array{baseCost: int, discountAmount: int, serviceChargeAmount: int, taxAmount: int, total: int}
     */
    private static function _calculateLineItemBreakdownWithContext(OrderProductPivot $lineItem, array $context): array
    {
        $allLineItems = $context['allLineItems'];
        $orderBaseCost = $context['orderBaseCost'];
        $orderScopedDiscounts = $context['orderScopedDiscounts'];
        $orderScopedTaxes = $context['orderScopedTaxes'];
        $orderScopedServiceCharges = $context['orderScopedServiceCharges'];
        $serviceChargeApplicableBaseCosts = $context['serviceChargeApplicableBaseCosts'];

        // Step 1: Base cost for this line item
        $lineItemBaseCost = self::_calculateLineItemBaseCost($lineItem);

        // Step 2: Apportionment ratio using pre-computed order base cost
        $ratio = ($orderBaseCost > 0) ? $lineItemBaseCost / $orderBaseCost : 0;

        // Step 3: Collect all applicable deductibles for this line item
        $isCustomLineItem = is_null($lineItem->product_id);

        $lineItemDiscounts = self::_mergeCollectionsByScope($lineItem->discounts ?? collect([]));
        $lineItemTaxes = self::_mergeCollectionsByScope($lineItem->taxes ?? collect([]));
        $lineItemServiceCharges = self::_mergeCollectionsByScope($lineItem->serviceCharges ?? collect([]));

        $applicableTaxes = $orderScopedTaxes;
        if ($isCustomLineItem) {
            $applicableTaxes = $applicableTaxes->filter(fn (Tax $tax) => $tax->appliesToCustomAmounts());
        }

        $allDiscounts = $lineItemDiscounts->merge($orderScopedDiscounts);
        $allTaxes = $lineItemTaxes->merge($applicableTaxes);
        $allServiceCharges = $lineItemServiceCharges->merge($orderScopedServiceCharges);

        // Step 4-5: Separate by phase
        $subtotalPhaseTaxes = $allTaxes->filter(fn (Tax $tax) => $tax->isCalculatedOnSubtotal());
        $totalPhaseTaxes = $allTaxes->filter(fn (Tax $tax) => $tax->isCalculatedOnTotal());

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

        // Step 7: Subtotal-phase service charges
        $subtotalServiceChargeBreakdown = self::_calculateLineItemServiceChargeBreakdownWithContext(
            $subtotalServiceCharges, $discountedCost, $lineItem, $allLineItems, $ratio, $serviceChargeApplicableBaseCosts
        );
        $subtotalSCAmount = $subtotalServiceChargeBreakdown->sum('amount');
        $taxableSubtotalSCAmount = $subtotalServiceChargeBreakdown
            ->filter(fn (array $entry) => $entry['service_charge']->taxable)
            ->sum('amount');
        $subtotalAmount = $discountedCost + $subtotalSCAmount;

        // Step 8: Subtotal-phase taxes
        $subtotalTaxBase = $discountedCost + $taxableSubtotalSCAmount;
        $subtotalTaxAmount = self::_calculateLineItemTaxes($subtotalPhaseTaxes, $subtotalTaxBase);
        $subtotalTaxedCost = $subtotalAmount + $subtotalTaxAmount;

        // Step 9: Total-phase service charges
        $totalServiceChargeBreakdown = self::_calculateLineItemServiceChargeBreakdownWithContext(
            $totalServiceCharges, $subtotalTaxedCost, $lineItem, $allLineItems, $ratio, $serviceChargeApplicableBaseCosts
        );
        $totalSCAmount = $totalServiceChargeBreakdown->sum('amount');
        $taxableTotalSCAmount = $totalServiceChargeBreakdown
            ->filter(fn (array $entry) => $entry['service_charge']->taxable)
            ->sum('amount');
        $preTotal = $subtotalTaxedCost + $totalSCAmount;

        // Step 10: Total-phase taxes
        $totalTaxBase = $subtotalTaxBase + $taxableTotalSCAmount;
        $totalTaxAmount = self::_calculateLineItemTaxes($totalPhaseTaxes, $totalTaxBase);
        $totalTaxedCost = $preTotal + $totalTaxAmount;

        // Step 11: Service charge taxes
        $scTaxAmount = self::_calculateLineItemServiceChargeTaxes(
            $subtotalServiceChargeBreakdown->merge($totalServiceChargeBreakdown)
        );

        return [
            'baseCost'            => $lineItemBaseCost,
            'discountAmount'      => $discountAmount,
            'serviceChargeAmount' => $subtotalSCAmount + $totalSCAmount,
            'taxAmount'           => $subtotalTaxAmount + $totalTaxAmount + $scTaxAmount,
            'total'               => $totalTaxedCost + $scTaxAmount,
        ];
    }

    /**
     * Pre-build a map of service charge ID to the applicable base cost denominator.
     *
     * @param Collection $allServiceCharges
     * @param Collection $allLineItems
     *
     * @return array<int, float|int> Map of serviceCharge ID to applicable base cost
     */
    private static function _buildServiceChargeApplicableBaseCosts(Collection $allServiceCharges, Collection $allLineItems): array
    {
        $map = [];

        foreach ($allServiceCharges as $sc) {
            $scope = self::_getScope($sc);

            if ($scope === Constants::DEDUCTIBLE_SCOPE_ORDER) {
                continue;
            }

            $applicableLineItems = $allLineItems->filter(function (OrderProductPivot $candidate) use ($sc) {
                $candidate->loadMissing('serviceCharges');

                return $candidate->serviceCharges->contains(fn ($attached) => $attached->id === $sc->id);
            });

            if ($applicableLineItems->isNotEmpty()) {
                $map[$sc->id] = self::_calculateAllLineItemsBaseCost($applicableLineItems);
            }
        }

        return $map;
    }

    /**
     * Service charge breakdown using pre-computed applicability map.
     *
     * @param Collection        $serviceCharges
     * @param float|int         $baseAmount
     * @param OrderProductPivot $lineItem
     * @param Collection        $allLineItems
     * @param float             $ratio
     * @param array             $serviceChargeApplicableBaseCosts
     *
     * @return Collection
     */
    private static function _calculateLineItemServiceChargeBreakdownWithContext(
        Collection $serviceCharges,
        float|int $baseAmount,
        OrderProductPivot $lineItem,
        Collection $allLineItems,
        float $ratio,
        array $serviceChargeApplicableBaseCosts
    ): Collection {
        if ($serviceCharges->isEmpty()) {
            return collect([]);
        }

        return $serviceCharges->map(function ($sc) use ($baseAmount, $lineItem, $allLineItems, $ratio, $serviceChargeApplicableBaseCosts) {
            return [
                'service_charge' => $sc,
                'amount'         => self::_calculateLineItemServiceChargeAmountWithContext(
                    $sc, $baseAmount, $lineItem, $allLineItems, $ratio, $serviceChargeApplicableBaseCosts
                ),
            ];
        });
    }

    /**
     * Calculate a single service charge amount using pre-computed applicability map.
     *
     * @param mixed             $serviceCharge
     * @param float|int         $baseAmount
     * @param OrderProductPivot $lineItem
     * @param Collection        $allLineItems
     * @param float             $ratio
     * @param array             $serviceChargeApplicableBaseCosts
     *
     * @return int
     */
    private static function _calculateLineItemServiceChargeAmountWithContext(
        $serviceCharge,
        float|int $baseAmount,
        OrderProductPivot $lineItem,
        Collection $allLineItems,
        float $ratio,
        array $serviceChargeApplicableBaseCosts
    ): int {
        $scope = self::_getScope($serviceCharge);

        self::_assertNotSubtotalOnProduct($scope, $serviceCharge->calculation_phase);

        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE) {
            if ($scope === Constants::DEDUCTIBLE_SCOPE_PRODUCT) {
                return self::_roundMoney(($serviceCharge->amount_money ?? 0) * ($lineItem->quantity ?? 1));
            }

            $apportionmentRatio = self::_calculateLineItemServiceChargeRatioWithContext(
                $serviceCharge, $lineItem, $ratio, $serviceChargeApplicableBaseCosts
            );

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
     * Calculate service charge ratio using pre-computed applicability map.
     *
     * @param mixed             $serviceCharge
     * @param OrderProductPivot $lineItem
     * @param float             $defaultRatio
     * @param array             $serviceChargeApplicableBaseCosts
     *
     * @return float
     */
    private static function _calculateLineItemServiceChargeRatioWithContext(
        $serviceCharge,
        OrderProductPivot $lineItem,
        float $defaultRatio,
        array $serviceChargeApplicableBaseCosts
    ): float {
        if (self::_getScope($serviceCharge) === Constants::DEDUCTIBLE_SCOPE_ORDER) {
            return $defaultRatio;
        }

        $applicableBaseCost = $serviceChargeApplicableBaseCosts[$serviceCharge->id] ?? 0;
        if ($applicableBaseCost <= 0) {
            return 0;
        }

        return self::_calculateLineItemBaseCost($lineItem) / $applicableBaseCost;
    }

    /**
     * Calculate base cost for a single line item: (base_price + modifiers) x quantity.
     *
     * @param OrderProductPivot $lineItem
     *
     * @return float|int
     */
    private static function _calculateLineItemBaseCost(OrderProductPivot $lineItem): float|int
    {
        $basePrice = $lineItem->base_price_money_amount ?? 0;
        $modifiers = $lineItem->modifiers;

        $modifierCost = $modifiers && $modifiers->isNotEmpty()
            ? $modifiers->sum(fn ($modifier) => $modifier->modifiable?->price_money_amount ?? 0)
            : 0;

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
        return $deductibles->filter(
            fn ($item) => self::_getScope($item) === Constants::DEDUCTIBLE_SCOPE_ORDER
        );
    }

    /**
     * Calculate discounts for a single line item.
     *
     * @param Collection $discounts
     * @param float|int  $lineItemBaseCost
     * @param float      $ratio Apportionment ratio for ORDER-scoped fixed amounts
     *
     * @return float|int Total discount amount
     */
    private static function _calculateLineItemDiscounts(Collection $discounts, float|int $lineItemBaseCost, float $ratio): float|int
    {
        if ($discounts->isEmpty()) {
            return 0;
        }

        // Pre-classify discounts into 4 buckets in a single pass:
        // [0] = product percentage, [1] = order percentage, [2] = product fixed, [3] = order fixed
        $groups = [[], [], [], []];
        foreach ($discounts as $discount) {
            $isProduct = self::_getScope($discount) === Constants::DEDUCTIBLE_SCOPE_PRODUCT;
            $isPercentage = (bool) $discount->percentage;
            $index = ($isProduct ? 0 : 1) + ($isPercentage ? 0 : 2);
            $groups[$index][] = $discount;
        }

        $runningAmount = $lineItemBaseCost;
        $totalDiscount = 0;

        foreach ($groups as $group) {
            foreach ($group as $discount) {
                $scope = self::_getScope($discount);
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
            fn ($tax) => self::_roundMoney($netPrice * $tax->percentage / 100)
        );
    }

    /**
     * Calculate service charge breakdown for a single line item.
     *
     * @param Collection        $serviceCharges
     * @param float|int         $baseAmount Current line item subtotal
     * @param OrderProductPivot $lineItem
     * @param Collection        $allLineItems
     * @param float             $ratio Apportionment ratio
     *
     * @return Collection
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
     * @param Collection $serviceChargeBreakdown
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
        $scope = self::_getScope($serviceCharge);

        self::_assertNotSubtotalOnProduct($scope, $serviceCharge->calculation_phase);

        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE) {
            if ($scope === Constants::DEDUCTIBLE_SCOPE_PRODUCT) {
                return self::_roundMoney(($serviceCharge->amount_money ?? 0) * ($lineItem->quantity ?? 1));
            }

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
        if (self::_getScope($serviceCharge) === Constants::DEDUCTIBLE_SCOPE_ORDER) {
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
     * Assert that a SUBTOTAL-phase service charge is not applied to a product scope.
     *
     * @param string $scope
     * @param string $calculationPhase
     *
     * @throws InvalidSquareOrderException
     */
    private static function _assertNotSubtotalOnProduct(string $scope, string $calculationPhase): void
    {
        if (
            $scope === Constants::DEDUCTIBLE_SCOPE_PRODUCT
            && $calculationPhase === OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE
        ) {
            throw new InvalidSquareOrderException('Service charge calculation phase "SUBTOTAL" cannot be applied to products in an order.');
        }
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
     * Extract scope from a deductible item (supports both pivot and direct scope).
     *
     * @param mixed $item
     *
     * @return string
     */
    private static function _getScope($item): string
    {
        return $item->pivot ? $item->pivot->scope : $item->scope;
    }
}
