<?php

namespace Nikolag\Square\Utils;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nikolag\Square\Dto\OrderTotalsBreakdown;
use Nikolag\Square\Exceptions\InvalidSquareOrderException;
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
     * @return int
     */
    public static function calculateTotalOrderCost(stdClass $orderCopy): int
    {
        $allServiceCharges = self::collectServiceCharges($orderCopy);

        return self::calculateTotalCost($orderCopy->discounts, $orderCopy->taxes, $allServiceCharges, $orderCopy->products);
    }

    /**
     * Calculates order total based on Model.
     *
     * @param Model $order
     *
     * @return int
     */
    public static function calculateTotalOrderCostByModel(Model $order): int
    {
        $order->loadMissing(['lineItems']);

        // Multi-line-item orders use the per-line-item breakdown for apportionment
        if ($order->lineItems->count() > 1) {
            return self::calculateOrderTotalsBreakdown($order)->totalAmount;
        }

        // Single line items use the order-level pipeline directly
        $allServiceCharges = self::collectServiceCharges($order);

        return self::calculateTotalCost($order->discounts, $order->taxes, $allServiceCharges, $order->products);
    }

    /**
     * Calculate the total cost for a single line item, including its apportioned
     * share of order-level taxes, discounts, and service charges.
     *
     * @param OrderProductPivot $lineItem The line item to calculate.
     * @param Model             $order    The parent order (for order-level deductibles).
     *
     * @return int The total cost for this line item in cents.
     */
    public static function calculateLineItemTotalByModel(OrderProductPivot $lineItem, Model $order): int
    {
        $lineItem->loadMissing(['taxes', 'discounts', 'serviceCharges', 'modifiers.modifiable']);
        $order->loadMissing(['lineItems.modifiers.modifiable', 'lineItems.serviceCharges', 'discounts', 'taxes']);

        $allServiceCharges = self::collectServiceCharges($order);
        $allLineItems = $order->lineItems;

        return self::calculateLineItemBreakdown($lineItem, $order->discounts, $order->taxes, $allServiceCharges, $allLineItems)['total'];
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

        $context = $order->lineItems->count() > 1
            ? self::buildOrderContext($order)
            : null;

        if (! $context) {
            $order->loadMissing([
                'lineItems.modifiers.modifiable',
                'lineItems.serviceCharges.taxes',
                'lineItems.taxes',
                'lineItems.discounts',
                'discounts',
                'taxes',
            ]);
        }

        $allServiceCharges = self::collectServiceCharges($order);
        $allLineItems = $context['allLineItems'] ?? $order->lineItems;

        $breakdowns = $allLineItems->map(
            fn (OrderProductPivot $lineItem) => self::calculateLineItemBreakdown(
                $lineItem, $order->discounts, $order->taxes, $allServiceCharges, $allLineItems, $context
            )
        );

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
    private static function buildOrderContext(Model $order): array
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

        $orderBaseCost = self::calculateAllLineItemsBaseCost($allLineItems);
        $orderScopedDiscounts = self::filterOrderScoped($order->discounts);
        $orderScopedTaxes = self::filterOrderScoped($order->taxes);
        $orderScopedServiceCharges = self::filterOrderScoped($allServiceCharges);

        $serviceChargeApplicableBaseCosts = self::buildServiceChargeApplicableBaseCosts($allServiceCharges, $allLineItems);

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
     * @param int        $noDeductiblesCost
     * @param Collection $products
     * @param array      $discountToProduct
     *
     * @return int
     */
    private static function calculateDiscounts(Collection $discounts, int $noDeductiblesCost, Collection $products, array $discountToProduct = []): int
    {
        if ($discounts->isEmpty() || $products->isEmpty()) {
            return 0;
        }

        // Build index on-demand if not provided
        if (empty($discountToProduct)) {
            $discountToProduct = self::buildDeductibleToProductIndex($products, 'discounts');
        }

        return $discounts->sum(function ($discount) use ($noDeductiblesCost, $discountToProduct) {
            return match (self::getScope($discount)) {
                Constants::DEDUCTIBLE_SCOPE_PRODUCT => self::calculateProductDiscounts($discount, $discountToProduct),
                Constants::DEDUCTIBLE_SCOPE_ORDER   => self::calculateOrderDiscounts($discount, $noDeductiblesCost),
                default                             => 0
            };
        });
    }

    /**
     * Function which calculates the net price by removing any additive taxes to the entire order.
     *
     * @param int        $discountCost
     * @param Collection $inclusiveTaxes
     *
     * @return int
     */
    private static function calculateNetPrice(int $discountCost, Collection $inclusiveTaxes): int
    {
        $inclusiveTaxPercent = $inclusiveTaxes->sum('percentage') / 100;

        return $discountCost / (1 + $inclusiveTaxPercent);
    }

    /**
     * Function which calculates discounts on order level and where percentage
     * takes over precedence over flat amount.
     *
     * @param       $discount
     * @param int   $noDeductiblesCost
     *
     * @return int
     */
    private static function calculateOrderDiscounts($discount, int $noDeductiblesCost): int
    {
        return $discount->percentage
            ? self::roundMoney($noDeductiblesCost * $discount->percentage / 100)
            : $discount->amount;
    }

    /**
     * Function which calculates discounts on product level and where percentage
     * takes over precedence over flat amount.
     *
     * @param $discount
     * @param array $discountToProduct
     *
     * @return int
     */
    private static function calculateProductDiscounts($discount, array $discountToProduct): int
    {
        $product = $discountToProduct[$discount->id] ?? null;

        if (! $product) {
            return 0;
        }

        return $discount->percentage
            ? self::roundMoney($product->pivot->base_price_money_amount * $product->pivot->quantity * $discount->percentage / 100)
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
     * @return int
     */
    private static function calculateProductTaxes($tax, Collection $inclusiveTaxes, array $taxToProduct, array $productDiscountedCosts): int
    {
        $product = $taxToProduct[$tax->id] ?? null;

        if (! $product) {
            return 0;
        }

        $productId = $product->pivot->id;
        $discountCost = $productDiscountedCosts[$productId] ?? ($product->pivot->base_price_money_amount * $product->pivot->quantity);

        $netPrice = self::calculateNetPrice($discountCost, $inclusiveTaxes);

        return self::roundMoney($netPrice * ($tax->percentage / 100));
    }

    /**
     * Function which calculates taxes on order level.
     *
     * @param int        $discountCost
     * @param            $tax
     * @param Collection $inclusiveTaxes
     *
     * @return int
     */
    private static function calculateOrderTaxes(int $discountCost, $tax, Collection $inclusiveTaxes): int
    {
        $netPrice = self::calculateNetPrice($discountCost, $inclusiveTaxes);

        $orderTaxes = $netPrice * $tax->percentage / 100;

        return self::roundMoney($orderTaxes);
    }

    /**
     * Function which calculates service charges on order level and where percentage
     * takes over precedence over flat amount.
     *
     * @param       $serviceCharge
     * @param int   $amount
     *
     * @return int
     */
    private static function calculateOrderServiceCharges($serviceCharge, int $amount): int
    {
        return $serviceCharge->percentage
            ? self::roundMoney($amount * $serviceCharge->percentage / 100)
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
     * @return int
     */
    private static function calculateProductServiceCharges(Collection $products, $serviceCharge, array $serviceChargeToProduct = []): int
    {
        self::assertNotSubtotalOnProduct(Constants::DEDUCTIBLE_SCOPE_PRODUCT, $serviceCharge->calculation_phase);

        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE) {
            $totalQuantity = $products->sum('pivot.quantity');

            return $serviceCharge->amount_money * $totalQuantity;
        }

        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE) {
            $totalValue = $products->sum(fn ($product) => $product->pivot->base_price_money_amount * $product->pivot->quantity);

            return $totalValue * $serviceCharge->percentage / 100;
        }

        // Use index for direct lookup if available, otherwise linear scan
        $targetProduct = ! empty($serviceChargeToProduct)
            ? ($serviceChargeToProduct[$serviceCharge->id] ?? null)
            : $products->first(fn ($product) => $product->pivot->serviceCharges->contains($serviceCharge));

        if (! $targetProduct) {
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
     * @param int        $baseAmount
     * @param Collection $products
     * @param array      $serviceChargeToProduct
     *
     * @return int
     */
    private static function calculateServiceCharges(Collection $serviceCharges, int $baseAmount, Collection $products, array $serviceChargeToProduct = []): int
    {
        if ($serviceCharges->isEmpty() || $products->isEmpty()) {
            return 0;
        }

        return $serviceCharges->sum(function ($serviceCharge) use ($products, $baseAmount, $serviceChargeToProduct) {
            return match (self::getScope($serviceCharge)) {
                Constants::DEDUCTIBLE_SCOPE_PRODUCT => self::calculateProductServiceCharges($products, $serviceCharge, $serviceChargeToProduct),
                Constants::DEDUCTIBLE_SCOPE_ORDER   => self::calculateOrderServiceCharges($serviceCharge, $baseAmount),
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
     * @return int
     */
    private static function calculateServiceChargeTaxes(Collection $serviceCharges, Collection $products, float|int $orderBaseAmount, array $serviceChargeToProduct = []): int
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
            $serviceChargeAmount = match (self::getScope($serviceCharge)) {
                Constants::DEDUCTIBLE_SCOPE_PRODUCT => self::calculateProductServiceCharges($products, $serviceCharge, $serviceChargeToProduct),
                Constants::DEDUCTIBLE_SCOPE_ORDER   => $serviceCharge->percentage ?
                    self::roundMoney($serviceCharge->percentage / 100 * $orderBaseAmount) :
                    $serviceCharge->amount_money,
                default => 0
            };

            // Apply taxes to the service charge amount
            return $serviceChargeTaxes->sum(function ($tax) use ($serviceChargeAmount) {
                return self::roundMoney($serviceChargeAmount * $tax->percentage / 100);
            });
        });
    }

    /**
     * Calculate all additive taxes on order level.
     *
     * @param Collection $taxes
     * @param int        $discountCost
     * @param Collection $products
     * @param Collection $discounts
     * @param array      $taxToProduct
     * @param array      $discountToProduct
     *
     * @return int
     */
    private static function calculateAdditiveTaxes(
        Collection $taxes,
        int $discountCost,
        Collection $products,
        Collection $discounts,
        array $taxToProduct = [],
        array $discountToProduct = []
    ): int {
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
            $taxToProduct = self::buildDeductibleToProductIndex($products, 'taxes');
        }
        if (empty($discountToProduct)) {
            $discountToProduct = self::buildDeductibleToProductIndex($products, 'discounts');
        }

        // Pre-compute discounted cost per product once (avoids O(T*D*P))
        $productDiscountedCosts = [];
        foreach ($products as $product) {
            $productId = $product->pivot->id;
            $totalCost = $product->pivot->base_price_money_amount * $product->pivot->quantity;
            $productDiscountedCosts[$productId] = $totalCost - self::calculateDiscounts($discounts, $totalCost, $products, $discountToProduct);
        }

        return $additiveTaxes->sum(function ($tax) use ($discountCost, $inclusiveTaxes, $taxToProduct, $productDiscountedCosts) {
            return match (self::getScope($tax)) {
                Constants::DEDUCTIBLE_SCOPE_PRODUCT => self::calculateProductTaxes($tax, $inclusiveTaxes, $taxToProduct, $productDiscountedCosts),
                Constants::DEDUCTIBLE_SCOPE_ORDER   => self::calculateOrderTaxes($discountCost, $tax, $inclusiveTaxes),
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
     * @return int
     */
    private static function calculateTotalCost(Collection $discounts, Collection $taxes, Collection $serviceCharges, Collection $products): int
    {
        // Early validation
        if ($products->isEmpty()) {
            throw new InvalidSquareOrderException('Total cost cannot be calculated without products.');
        }

        // Pre-filter all collections by scope once for efficiency
        $allDiscounts = self::mergeCollectionsByScope($discounts);
        $allTaxes = self::mergeCollectionsByScope($taxes);
        $allServiceCharges = self::mergeCollectionsByScope($serviceCharges);

        // Separate taxes by calculation phase
        $subtotalPhaseTaxes = $allTaxes->filter(fn (Tax $tax) => $tax->isCalculatedOnSubtotal());
        $totalPhaseTaxes = $allTaxes->filter(fn (Tax $tax) => $tax->isCalculatedOnTotal());

        // Separate service charges by calculation phase
        $subtotalServiceCharges = $allServiceCharges->filter(fn ($serviceCharge) => in_array($serviceCharge->calculation_phase, [
            OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE,
            OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE,
            OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE,
        ]));
        $totalServiceCharges = $allServiceCharges->filter(
            fn ($serviceCharge) => $serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::TOTAL_PHASE
        );

        // Build reverse-index maps once for O(1) lookups
        $discountToProduct = self::buildDeductibleToProductIndex($products, 'discounts');
        $taxToProduct = self::buildDeductibleToProductIndex($products, 'taxes');
        $serviceChargeToProduct = self::buildDeductibleToProductIndex($products, 'serviceCharges');

        // Calculate base cost only once
        $noDeductiblesCost = self::calculateProductTotals($products);

        // Apply discounts first to the subtotal
        $discountCost = $noDeductiblesCost - self::calculateDiscounts($allDiscounts, $noDeductiblesCost, $products, $discountToProduct);

        // Add subtotal-phase service charges to discount cost
        $subtotalSCAmount = self::calculateServiceCharges($subtotalServiceCharges, $discountCost, $products, $serviceChargeToProduct);
        $taxableSubtotalSCAmount = self::calculateServiceCharges(
            $subtotalServiceCharges->filter(fn ($serviceCharge) => $serviceCharge->taxable), $discountCost, $products, $serviceChargeToProduct
        );
        $subTotalAmount = $discountCost + $subtotalSCAmount;

        // Apply subtotal-phase taxes (only taxable service charges are included in the tax base)
        $subtotalTaxBase = $discountCost + $taxableSubtotalSCAmount;
        $subtotalTaxedCost = $subTotalAmount + self::calculateAdditiveTaxes($subtotalPhaseTaxes, $subtotalTaxBase, $products, $allDiscounts, $taxToProduct, $discountToProduct);

        // Add total-phase service charges after subtotal taxes
        $totalServiceChargeAmount = self::calculateServiceCharges($totalServiceCharges, $subtotalTaxedCost, $products, $serviceChargeToProduct);
        $taxableTotalSCAmount = self::calculateServiceCharges(
            $totalServiceCharges->filter(fn ($serviceCharge) => $serviceCharge->taxable), $subtotalTaxedCost, $products, $serviceChargeToProduct
        );
        $preTotal = $subtotalTaxedCost + $totalServiceChargeAmount;

        // Apply total-phase taxes
        $totalTaxBase = $subtotalTaxBase + $taxableTotalSCAmount;
        $totalTaxedCost = $preTotal + self::calculateAdditiveTaxes($totalPhaseTaxes, $totalTaxBase, $products, $allDiscounts, $taxToProduct, $discountToProduct);

        // Finally, calculate service charge taxes
        $serviceChargeTaxAmount = self::calculateServiceChargeTaxes($allServiceCharges, $products, $noDeductiblesCost, $serviceChargeToProduct);

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
    private static function buildDeductibleToProductIndex(Collection $products, string $relation): array
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
                if (! isset($index[$item->id])) {
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
    private static function mergeCollectionsByScope(Collection $collection): Collection
    {
        if ($collection->isEmpty()) {
            return collect([]);
        }

        return $collection->filter(
            fn ($obj) => in_array(self::getScope($obj), [Constants::DEDUCTIBLE_SCOPE_ORDER, Constants::DEDUCTIBLE_SCOPE_PRODUCT])
        );
    }

    /**
     * Calculate product totals once and cache for reuse.
     *
     * @param Collection $products
     *
     * @return int
     */
    private static function calculateProductTotals(Collection $products): int
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
     * Follows the same sequence as calculateTotalCost:
     * base cost -> discounts -> subtotal service charges -> subtotal taxes
     * -> total service charges -> total taxes -> service charge taxes
     *
     * When $context is provided (from buildOrderContext), uses pre-computed order-level
     * state to avoid redundant work across multiple line items.
     *
     * @return array{baseCost: int, discountAmount: int, serviceChargeAmount: int, taxAmount: int, total: int}
     */
    private static function calculateLineItemBreakdown(
        OrderProductPivot $lineItem,
        Collection $orderDiscounts,
        Collection $orderTaxes,
        Collection $orderServiceCharges,
        Collection $allLineItems,
        ?array $context = null
    ): array {
        $serviceChargeApplicableBaseCosts = $context['serviceChargeApplicableBaseCosts'] ?? null;

        // Step 1: Base cost for this line item
        $lineItemBaseCost = self::calculateLineItemBaseCost($lineItem);

        // Step 2: Apportionment ratio (this line item's share of order gross sales)
        $orderBaseCost = $context['orderBaseCost'] ?? self::calculateAllLineItemsBaseCost($allLineItems);
        $ratio = ($orderBaseCost > 0) ? $lineItemBaseCost / $orderBaseCost : 0;

        // Step 3: Collect all applicable deductibles for this line item
        $isCustomLineItem = is_null($lineItem->product_id);

        $lineItemDiscounts = self::mergeCollectionsByScope($lineItem->discounts ?? collect([]));
        $lineItemTaxes = self::mergeCollectionsByScope($lineItem->taxes ?? collect([]));
        $lineItemServiceCharges = self::mergeCollectionsByScope($lineItem->serviceCharges ?? collect([]));

        $orderScopedDiscounts = $context['orderScopedDiscounts'] ?? self::filterOrderScoped($orderDiscounts);
        $orderScopedTaxes = $context['orderScopedTaxes'] ?? self::filterOrderScoped($orderTaxes);
        $orderScopedServiceCharges = $context['orderScopedServiceCharges'] ?? self::filterOrderScoped($orderServiceCharges);

        if ($isCustomLineItem) {
            $orderScopedTaxes = $orderScopedTaxes->filter(
                fn (Tax $tax) => $tax->appliesToCustomAmounts()
            );
        }

        $allDiscounts = $lineItemDiscounts->merge($orderScopedDiscounts);
        $allTaxes = $lineItemTaxes->merge($orderScopedTaxes);
        $allServiceCharges = $lineItemServiceCharges->merge($orderScopedServiceCharges);

        // Step 4: Separate taxes by calculation phase
        $subtotalPhaseTaxes = $allTaxes->filter(fn (Tax $tax) => $tax->isCalculatedOnSubtotal());
        $totalPhaseTaxes = $allTaxes->filter(fn (Tax $tax) => $tax->isCalculatedOnTotal());

        // Step 5: Separate service charges by calculation phase
        $subtotalServiceCharges = $allServiceCharges->filter(fn ($serviceCharge) => in_array($serviceCharge->calculation_phase, [
            OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE,
            OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE,
            OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE,
        ]));
        $totalServiceCharges = $allServiceCharges->filter(
            fn ($serviceCharge) => $serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::TOTAL_PHASE
        );

        // Step 6: Apply discounts
        $discountAmount = self::calculateLineItemDiscounts($allDiscounts, $lineItemBaseCost, $ratio);
        $discountedCost = $lineItemBaseCost - $discountAmount;

        // Step 7: Add subtotal-phase service charges
        $subtotalSCBreakdown = self::calculateLineItemServiceChargeBreakdown(
            $subtotalServiceCharges, $discountedCost, $lineItem, $allLineItems, $ratio, $serviceChargeApplicableBaseCosts
        );
        $subtotalSCAmount = $subtotalSCBreakdown->sum('amount');
        $taxableSubtotalSCAmount = $subtotalSCBreakdown
            ->filter(fn (array $entry) => $entry['service_charge']->taxable)
            ->sum('amount');
        $subtotalAmount = $discountedCost + $subtotalSCAmount;

        // Step 8: Add subtotal-phase taxes
        $subtotalTaxBase = $discountedCost + $taxableSubtotalSCAmount;
        $subtotalTaxAmount = self::calculateLineItemTaxes($subtotalPhaseTaxes, $subtotalTaxBase);
        $subtotalTaxedCost = $subtotalAmount + $subtotalTaxAmount;

        // Step 9: Add total-phase service charges
        $totalSCBreakdown = self::calculateLineItemServiceChargeBreakdown(
            $totalServiceCharges, $subtotalTaxedCost, $lineItem, $allLineItems, $ratio, $serviceChargeApplicableBaseCosts
        );
        $totalSCAmount = $totalSCBreakdown->sum('amount');
        $taxableTotalSCAmount = $totalSCBreakdown
            ->filter(fn (array $entry) => $entry['service_charge']->taxable)
            ->sum('amount');
        $preTotal = $subtotalTaxedCost + $totalSCAmount;

        // Step 10: Add total-phase taxes
        $totalTaxBase = $subtotalTaxBase + $taxableTotalSCAmount;
        $totalTaxAmount = self::calculateLineItemTaxes($totalPhaseTaxes, $totalTaxBase);
        $totalTaxedCost = $preTotal + $totalTaxAmount;

        // Step 11: Add service charge taxes
        $serviceChargeTaxAmount = self::calculateLineItemServiceChargeTaxes(
            $subtotalSCBreakdown->merge($totalSCBreakdown)
        );

        return [
            'baseCost'            => $lineItemBaseCost,
            'discountAmount'      => $discountAmount,
            'serviceChargeAmount' => $subtotalSCAmount + $totalSCAmount,
            'taxAmount'           => $subtotalTaxAmount + $totalTaxAmount + $serviceChargeTaxAmount,
            'total'               => $totalTaxedCost + $serviceChargeTaxAmount,
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
    private static function buildServiceChargeApplicableBaseCosts(Collection $allServiceCharges, Collection $allLineItems): array
    {
        $map = [];

        foreach ($allServiceCharges as $serviceCharge) {
            $scope = self::getScope($serviceCharge);

            if ($scope === Constants::DEDUCTIBLE_SCOPE_ORDER) {
                continue;
            }

            $applicableLineItems = $allLineItems->filter(function (OrderProductPivot $candidate) use ($serviceCharge) {
                $candidate->loadMissing('serviceCharges');

                return $candidate->serviceCharges->contains(fn ($attached) => $attached->id === $serviceCharge->id);
            });

            if ($applicableLineItems->isNotEmpty()) {
                $map[$serviceCharge->id] = self::calculateAllLineItemsBaseCost($applicableLineItems);
            }
        }

        return $map;
    }

    /**
     * Calculate base cost for a single line item: (base_price + modifiers) x quantity.
     *
     * @param OrderProductPivot $lineItem
     *
     * @return int
     */
    private static function calculateLineItemBaseCost(OrderProductPivot $lineItem): int
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
     * @return int
     */
    private static function calculateAllLineItemsBaseCost(Collection $lineItems): int
    {
        return $lineItems->sum(fn (OrderProductPivot $lineItem) => self::calculateLineItemBaseCost($lineItem));
    }

    /**
     * Filter a collection of deductibles to only ORDER-scoped items.
     *
     * @param Collection $deductibles
     *
     * @return Collection
     */
    private static function filterOrderScoped(Collection $deductibles): Collection
    {
        return $deductibles->filter(
            fn ($item) => self::getScope($item) === Constants::DEDUCTIBLE_SCOPE_ORDER
        );
    }

    /**
     * Calculate discounts for a single line item.
     *
     * @param Collection $discounts
     * @param float|int  $lineItemBaseCost
     * @param float      $ratio Apportionment ratio for ORDER-scoped fixed amounts
     *
     * @return int Total discount amount
     */
    private static function calculateLineItemDiscounts(Collection $discounts, float|int $lineItemBaseCost, float $ratio): int
    {
        if ($discounts->isEmpty()) {
            return 0;
        }

        // Pre-classify discounts into 4 buckets in a single pass:
        // [0] = product percentage, [1] = order percentage, [2] = product fixed, [3] = order fixed
        $groups = [[], [], [], []];
        foreach ($discounts as $discount) {
            $isProduct = self::getScope($discount) === Constants::DEDUCTIBLE_SCOPE_PRODUCT;
            $isPercentage = (bool) $discount->percentage;
            $index = ($isProduct ? 0 : 1) + ($isPercentage ? 0 : 2);
            $groups[$index][] = $discount;
        }

        $runningAmount = $lineItemBaseCost;
        $totalDiscount = 0;

        foreach ($groups as $group) {
            foreach ($group as $discount) {
                if ($discount->percentage) {
                    $discountAmount = self::roundMoney($runningAmount * $discount->percentage / 100);
                } elseif (self::getScope($discount) === Constants::DEDUCTIBLE_SCOPE_ORDER) {
                    $discountAmount = self::roundMoney(($discount->amount ?? 0) * $ratio);
                } else {
                    $discountAmount = $discount->amount ?? 0;
                }

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
     * @return int Total tax amount
     */
    private static function calculateLineItemTaxes(Collection $taxes, float|int $baseAmount): int
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
        $netPrice = self::calculateNetPrice($baseAmount, $inclusiveTaxes);

        return (int) $additiveTaxes->sum(
            fn ($tax) => self::roundMoney($netPrice * $tax->percentage / 100)
        );
    }

    /**
     * Calculate service charge breakdown for a single line item.
     *
     * When $serviceChargeApplicableBaseCosts is provided, uses pre-computed map for ratio lookups.
     */
    private static function calculateLineItemServiceChargeBreakdown(
        Collection $serviceCharges,
        float|int $baseAmount,
        OrderProductPivot $lineItem,
        Collection $allLineItems,
        float $ratio,
        ?array $serviceChargeApplicableBaseCosts = null
    ): Collection {
        if ($serviceCharges->isEmpty()) {
            return collect([]);
        }

        return $serviceCharges->map(function ($serviceCharge) use ($baseAmount, $lineItem, $allLineItems, $ratio, $serviceChargeApplicableBaseCosts) {
            return [
                'service_charge' => $serviceCharge,
                'amount'         => self::calculateLineItemServiceChargeAmount(
                    $serviceCharge, $baseAmount, $lineItem, $allLineItems, $ratio, $serviceChargeApplicableBaseCosts
                ),
            ];
        });
    }

    /**
     * Calculate taxes on service charges for a single line item.
     *
     * @param Collection $serviceChargeBreakdown
     *
     * @return int
     */
    private static function calculateLineItemServiceChargeTaxes(Collection $serviceChargeBreakdown): int
    {
        if ($serviceChargeBreakdown->isEmpty()) {
            return 0;
        }

        return $serviceChargeBreakdown->sum(function (array $serviceChargeData) {
            /** @var mixed $serviceCharge */
            $serviceCharge = $serviceChargeData['service_charge'];
            $serviceChargeAmount = $serviceChargeData['amount'];

            // Apportioned service charges inherit taxes from line items — no direct taxes
            if (
                $serviceCharge->treatment_type === OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT
                || $serviceCharge->taxable === false
            ) {
                return 0;
            }

            $serviceChargeTaxes = $serviceCharge->taxes ?? collect([]);
            if ($serviceChargeTaxes->isEmpty()) {
                return 0;
            }

            // Apply each tax to the service charge amount
            return $serviceChargeTaxes->sum(fn ($tax) => self::roundMoney($serviceChargeAmount * $tax->percentage / 100));
        });
    }

    /**
     * Calculate a single service charge amount attributable to one line item.
     *
     * When $serviceChargeApplicableBaseCosts is provided, uses pre-computed map for ratio lookups.
     */
    private static function calculateLineItemServiceChargeAmount(
        mixed $serviceCharge,
        float|int $baseAmount,
        OrderProductPivot $lineItem,
        Collection $allLineItems,
        float $ratio,
        ?array $serviceChargeApplicableBaseCosts = null
    ): int {
        $scope = self::getScope($serviceCharge);

        self::assertNotSubtotalOnProduct($scope, $serviceCharge->calculation_phase);

        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE) {
            if ($scope === Constants::DEDUCTIBLE_SCOPE_PRODUCT) {
                return self::roundMoney(($serviceCharge->amount_money ?? 0) * ($lineItem->quantity ?? 1));
            }

            $apportionmentRatio = self::calculateLineItemServiceChargeRatio(
                $serviceCharge, $lineItem, $allLineItems, $ratio, $serviceChargeApplicableBaseCosts
            );

            return self::roundMoney(($serviceCharge->amount_money ?? 0) * $apportionmentRatio);
        }

        if ($serviceCharge->calculation_phase === OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE) {
            return self::roundMoney($baseAmount * ($serviceCharge->percentage ?? 0) / 100);
        }

        if ($scope === Constants::DEDUCTIBLE_SCOPE_ORDER) {
            if ($serviceCharge->percentage) {
                return self::roundMoney($baseAmount * $serviceCharge->percentage / 100);
            }

            return self::roundMoney(($serviceCharge->amount_money ?? 0) * $ratio);
        }

        if ($serviceCharge->percentage) {
            return self::roundMoney($baseAmount * $serviceCharge->percentage / 100);
        }

        return (int) ($serviceCharge->amount_money ?? 0);
    }

    /**
     * Calculate the applicable apportionment ratio for a service charge.
     *
     * When $serviceChargeApplicableBaseCosts is provided, uses pre-computed map for O(1) lookup.
     * Otherwise, filters $allLineItems on-the-fly.
     */
    private static function calculateLineItemServiceChargeRatio(
        mixed $serviceCharge,
        OrderProductPivot $lineItem,
        Collection $allLineItems,
        float $defaultRatio,
        ?array $serviceChargeApplicableBaseCosts = null
    ): float {
        if (self::getScope($serviceCharge) === Constants::DEDUCTIBLE_SCOPE_ORDER) {
            return $defaultRatio;
        }

        if ($serviceChargeApplicableBaseCosts !== null) {
            $applicableBaseCost = $serviceChargeApplicableBaseCosts[$serviceCharge->id] ?? 0;

            return ($applicableBaseCost > 0)
                ? self::calculateLineItemBaseCost($lineItem) / $applicableBaseCost
                : 0;
        }

        $applicableLineItems = $allLineItems->filter(function (OrderProductPivot $candidate) use ($serviceCharge) {
            $candidate->loadMissing('serviceCharges');

            return $candidate->serviceCharges->contains(fn ($attached) => $attached->id === $serviceCharge->id);
        });

        if ($applicableLineItems->isEmpty()) {
            return 0;
        }

        $applicableBaseCost = self::calculateAllLineItemsBaseCost($applicableLineItems);

        return ($applicableBaseCost > 0)
            ? self::calculateLineItemBaseCost($lineItem) / $applicableBaseCost
            : 0;
    }

    /**
     * Assert that a SUBTOTAL-phase service charge is not applied to a product scope.
     *
     * @param string $scope
     * @param string $calculationPhase
     *
     * @throws InvalidSquareOrderException
     */
    private static function assertNotSubtotalOnProduct(string $scope, string $calculationPhase): void
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
    private static function roundMoney(float|int $amount): int
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
    private static function getScope($item): string
    {
        return $item->pivot ? $item->pivot->scope : $item->scope;
    }
}
