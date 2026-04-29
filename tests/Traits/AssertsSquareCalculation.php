<?php

namespace Nikolag\Square\Tests\Traits;

use Nikolag\Square\Facades\Square;
use Square\Models\CalculateOrderResponse;

/**
 * Provides helpers for comparing internal order totals against Square's CalculateOrder API.
 *
 * Used for troubleshooting and validating that our local calculation logic
 * matches Square's server-side results.
 */
trait AssertsSquareCalculation
{
    /**
     * Compare an internal order total calculation with Square's CalculateOrder API response.
     *
     * Returns a structured array showing whether the totals match and a breakdown
     * of Square's calculation components for debugging discrepancies.
     *
     * @param float|int              $internalTotal  The total from calculateTotalOrderCost()/calculateTotalOrderCostByModel().
     * @param CalculateOrderResponse $squareResponse The response from Square's CalculateOrder API.
     *
     * @return array{
     *   matches: bool,
     *   internal_total: int,
     *   square_total: int|null,
     *   difference: int,
     *   square_breakdown: array{
     *     total_money: int|null,
     *     total_tax_money: int|null,
     *     total_discount_money: int|null,
     *     total_service_charge_money: int|null,
     *   }
     * }
     */
    private function compareWithSquareCalculation(float|int $internalTotal, CalculateOrderResponse $squareResponse): array
    {
        $squareOrder = $squareResponse->getOrder();

        $squareTotal = $squareOrder?->getTotalMoney()?->getAmount();
        $squareTaxTotal = $squareOrder?->getTotalTaxMoney()?->getAmount();
        $squareDiscountTotal = $squareOrder?->getTotalDiscountMoney()?->getAmount();
        $squareServiceChargeTotal = $squareOrder?->getTotalServiceChargeMoney()?->getAmount();

        $internalTotalInt = (int) $internalTotal;
        $difference = $squareTotal !== null ? $internalTotalInt - $squareTotal : 0;

        return [
            'matches'          => $squareTotal !== null && $internalTotalInt === $squareTotal,
            'internal_total'   => $internalTotalInt,
            'square_total'     => $squareTotal,
            'difference'       => $difference,
            'square_breakdown' => [
                'total_money'                => $squareTotal,
                'total_tax_money'            => $squareTaxTotal,
                'total_discount_money'       => $squareDiscountTotal,
                'total_service_charge_money' => $squareServiceChargeTotal,
            ],
        ];
    }

    /**
     * Validate internal calculation against Square's CalculateOrder API when enabled.
     *
     * Gated behind SQUARE_VALIDATE_CALCULATIONS env flag so tests run fast by default
     * and only hit the Square API when explicitly opted in.
     *
     * @param object $order         The order model with relationships loaded.
     * @param int    $internalTotal The internally calculated total.
     */
    protected function validateAgainstSquareApi(object $order, int $internalTotal): void
    {
        if (env('SQUARE_VALIDATE_CALCULATIONS', false)) {
            $this->assertMatchesSquareCalculation($order, $internalTotal);
        }
    }

    /**
     * Validate internal calculation against Square's CalculateOrder API.
     *
     * @param mixed $order         The order model with relationships loaded.
     * @param int   $internalTotal The internally calculated total.
     */
    private function assertMatchesSquareCalculation($order, int $internalTotal): void
    {
        $order->loadMissing('products', 'taxes', 'discounts', 'serviceCharges', 'fulfillments');
        $squareResponse = Square::calculateOrder($order, env('SQUARE_LOCATION'));
        $comparison = $this->compareWithSquareCalculation($internalTotal, $squareResponse);
        $this->assertTrue(
            $comparison['matches'],
            sprintf(
                'Internal total (%d) does not match Square total (%d). Difference: %d. Square breakdown: tax=%d, discount=%d, service_charge=%d',
                $comparison['internal_total'],
                $comparison['square_total'],
                $comparison['difference'],
                $comparison['square_breakdown']['total_tax_money'] ?? 0,
                $comparison['square_breakdown']['total_discount_money'] ?? 0,
                $comparison['square_breakdown']['total_service_charge_money'] ?? 0
            )
        );
    }
}
