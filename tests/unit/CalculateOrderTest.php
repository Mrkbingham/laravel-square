<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Facades\Square;
use Nikolag\Square\Models\Discount;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Models\Product as ModelsProduct;
use Nikolag\Square\Models\ServiceCharge;
use Nikolag\Square\Models\Tax;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Tests\Traits\AssertsSquareCalculation;
use Nikolag\Square\Tests\Traits\MocksSquareConfigDependency;
use Nikolag\Square\Utils\Constants;
use Nikolag\Square\Utils\OrderCalculator;
use Square\Models\Builders\CalculateOrderResponseBuilder;
use Square\Models\Builders\MoneyBuilder;
use Square\Models\Builders\OrderBuilder;
use Square\Models\CalculateOrderRequest;
use Square\Models\CalculateOrderResponse;
use Square\Models\OrderServiceChargeCalculationPhase;
use Square\Models\OrderServiceChargeTreatmentType;

class CalculateOrderTest extends TestCase
{
    use AssertsSquareCalculation;
    use MocksSquareConfigDependency;

    /**
     * Test that buildCalculateOrderRequest returns a valid CalculateOrderRequest.
     */
    public function test_build_calculate_order_request(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create(['price' => 1500]);

        $tax = factory(Tax::class)->create([
            'percentage' => 8.5,
            'type'       => Constants::TAX_ADDITIVE,
        ]);

        $discount = factory(Discount::class)->create([
            'percentage' => 10.0,
            'amount'     => null,
        ]);

        $square = Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($product, 2)
            ->save();

        $order->taxes()->attach($tax->id, [
            'deductible_type' => Constants::TAX_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        $order->discounts()->attach($discount->id, [
            'deductible_type' => Constants::DISCOUNT_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        $order->refresh();
        $order->load('products', 'taxes', 'discounts', 'serviceCharges', 'fulfillments');

        $squareBuilder = Square::getSquareBuilder();
        $request = $squareBuilder->buildCalculateOrderRequest($order, env('SQUARE_LOCATION'), 'USD');

        $this->assertInstanceOf(CalculateOrderRequest::class, $request);

        $squareOrder = $request->getOrder();
        $this->assertNotNull($squareOrder);
        $this->assertNotEmpty($squareOrder->getLineItems());
        $this->assertNotEmpty($squareOrder->getTaxes());
        $this->assertNotEmpty($squareOrder->getDiscounts());
    }

    /**
     * Test that buildOrderRequest still works after the refactor.
     */
    public function test_build_order_request_after_refactor(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create(['price' => 2000]);

        $serviceCharge = factory(ServiceCharge::class)->create([
            'name'              => 'Test Fee',
            'amount_money'      => 500,
            'amount_currency'   => 'USD',
            'calculation_phase' => OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE,
            'treatment_type'    => OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT,
            'taxable'           => false,
        ]);

        $square = Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($product, 1)
            ->save();

        $order->serviceCharges()->attach($serviceCharge->id, [
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        $order->refresh();
        $order->load('products', 'taxes', 'discounts', 'serviceCharges', 'fulfillments');

        $squareBuilder = Square::getSquareBuilder();
        $request = $squareBuilder->buildOrderRequest($order, env('SQUARE_LOCATION'), 'USD');

        $this->assertNotNull($request->getOrder());
        $this->assertNotEmpty($request->getOrder()->getLineItems());
        $this->assertNotNull($request->getIdempotencyKey());
    }

    /**
     * Test calculateOrder service method with mocked success response.
     */
    public function test_calculate_order_success(): void
    {
        $this->mockCalculateOrderSuccess([
            'locationId'              => env('SQUARE_LOCATION'),
            'state'                   => 'OPEN',
            'totalMoney'              => 4671,
            'totalTaxMoney'           => 349,
            'totalDiscountMoney'      => 400,
            'totalServiceChargeMoney' => 722,
        ]);

        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create(['price' => 1000]);

        Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($product, 1)
            ->save();

        $order->refresh();
        $order->load('products', 'taxes', 'discounts', 'serviceCharges', 'fulfillments');

        $response = Square::calculateOrder($order, env('SQUARE_LOCATION'));

        $this->assertInstanceOf(CalculateOrderResponse::class, $response);
        $this->assertNotNull($response->getOrder());
        $this->assertEquals(4671, $response->getOrder()->getTotalMoney()->getAmount());
    }

    /**
     * Test calculateOrder service method with mocked error response.
     */
    public function test_calculate_order_error(): void
    {
        $this->mockCalculateOrderError('Invalid order data', 400);

        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create(['price' => 1000]);

        Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($product, 1)
            ->save();

        $order->refresh();
        $order->load('products', 'taxes', 'discounts', 'serviceCharges', 'fulfillments');

        $this->expectException(\Exception::class);

        Square::calculateOrder($order, env('SQUARE_LOCATION'));
    }

    /**
     * Test compareWithSquareCalculation when totals match.
     */
    public function test_compare_with_square_calculation_matches(): void
    {
        $squareResponse = CalculateOrderResponseBuilder::init()
            ->order(
                OrderBuilder::init('test-location')
                    ->totalMoney(MoneyBuilder::init()->amount(4671)->currency('USD')->build())
                    ->totalTaxMoney(MoneyBuilder::init()->amount(349)->currency('USD')->build())
                    ->totalDiscountMoney(MoneyBuilder::init()->amount(400)->currency('USD')->build())
                    ->totalServiceChargeMoney(MoneyBuilder::init()->amount(722)->currency('USD')->build())
                    ->build()
            )
            ->build();

        $result = $this->compareWithSquareCalculation(4671, $squareResponse);

        $this->assertTrue($result['matches']);
        $this->assertEquals(4671, $result['internal_total']);
        $this->assertEquals(4671, $result['square_total']);
        $this->assertEquals(0, $result['difference']);
        $this->assertEquals(349, $result['square_breakdown']['total_tax_money']);
        $this->assertEquals(400, $result['square_breakdown']['total_discount_money']);
        $this->assertEquals(722, $result['square_breakdown']['total_service_charge_money']);
    }

    /**
     * Test compareWithSquareCalculation when totals do not match.
     */
    public function test_compare_with_square_calculation_mismatch(): void
    {
        $squareResponse = CalculateOrderResponseBuilder::init()
            ->order(
                OrderBuilder::init('test-location')
                    ->totalMoney(MoneyBuilder::init()->amount(4670)->currency('USD')->build())
                    ->totalTaxMoney(MoneyBuilder::init()->amount(348)->currency('USD')->build())
                    ->totalDiscountMoney(MoneyBuilder::init()->amount(400)->currency('USD')->build())
                    ->totalServiceChargeMoney(MoneyBuilder::init()->amount(722)->currency('USD')->build())
                    ->build()
            )
            ->build();

        $result = $this->compareWithSquareCalculation(4671, $squareResponse);

        $this->assertFalse($result['matches']);
        $this->assertEquals(4671, $result['internal_total']);
        $this->assertEquals(4670, $result['square_total']);
        $this->assertEquals(1, $result['difference']);
    }

    /**
     * Test compareWithSquareCalculation when Square response has no order.
     */
    public function test_compare_with_square_calculation_null_order(): void
    {
        $squareResponse = CalculateOrderResponseBuilder::init()->build();

        $result = $this->compareWithSquareCalculation(4671, $squareResponse);

        $this->assertFalse($result['matches']);
        $this->assertNull($result['square_total']);
        $this->assertEquals(0, $result['difference']);
    }

    /**
     * Validate internal calculation against Square's CalculateOrder API
     * using the same scenario as test_service_charge_integration_with_full_order_workflow
     * but with Square-valid calculation phases.
     *
     * Scenario:
     *   - Product 1: $10.00 x 2 = $20.00
     *   - Product 2: $20.00 x 1 = $20.00
     *   - Subtotal: $40.00
     *   - 10% order-level discount
     *   - $5.00 apportioned-amount service charge (APPORTIONED_AMOUNT_PHASE)
     *   - 5% apportioned-percentage service charge on first product (APPORTIONED_PERCENTAGE_PHASE)
     *   - 8.5% additive tax (order-level)
     *
     * @group live
     */
    public function test_validate_full_workflow_against_square_calculate_order(): void
    {
        // Create test data - mirrors ServiceChargeIntegrationTest exactly
        $order = factory(Order::class)->create();
        $product1 = factory(ModelsProduct::class)->create(['price' => 10_00]); // $10.00
        $product2 = factory(Product::class)->create(['price' => 20_00]); // $20.00

        // Create service charges using Square-valid calculation phases:
        // - Amount-based apportioned charges must use APPORTIONED_AMOUNT_PHASE
        // - Percentage-based apportioned charges must use APPORTIONED_PERCENTAGE_PHASE
        $orderServiceCharge = factory(ServiceCharge::class)->create([
            'name'              => 'Handling Fee',
            'amount_money'      => 5_00, // $5.00
            'calculation_phase' => OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE,
            'treatment_type'    => OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT,
            'taxable'           => false,
        ]);

        $productServiceCharge = factory(ServiceCharge::class)->create([
            'name'              => 'Fake Percentage Fee',
            'percentage'        => 5.0,
            'treatment_type'    => OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT,
            'calculation_phase' => OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE,
            'taxable'           => false,
        ]);

        // Create tax and discount
        $tax = factory(Tax::class)->create([
            'percentage' => 8.5,
            'type'       => Constants::TAX_ADDITIVE,
        ]);

        $discount = factory(Discount::class)->create([
            'percentage' => 10.0,
            'amount'     => null,
        ]);

        // Build order through Square service
        Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($product1, 2) // 2 x $10.00 = $20.00
            ->addProduct($product2, 1) // 1 x $20.00 = $20.00
            ->save();

        // Attach order-level service charge
        $order->serviceCharges()->attach($orderServiceCharge->id, [
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        // Attach product-level service charge to first product
        $order->products->first()->pivot->serviceCharges()->attach($productServiceCharge->id, [
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'featurable_type' => Constants::ORDER_PRODUCT_NAMESPACE,
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        // Attach tax and discount at order level
        $order->taxes()->attach($tax->id, [
            'deductible_type' => Constants::TAX_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        $order->discounts()->attach($discount->id, [
            'deductible_type' => Constants::DISCOUNT_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        // Refresh the order to get updated relationships
        $order->refresh();
        $order->load('products', 'serviceCharges', 'taxes', 'discounts', 'fulfillments');

        // Calculate using our internal logic
        $internalTotal = OrderCalculator::calculateTotalOrderCostByModel($order);

        // Send to Square's CalculateOrder endpoint for validation
        $squareResponse = Square::calculateOrder($order, env('SQUARE_LOCATION'));

        // Compare internal vs Square
        $comparison = $this->compareWithSquareCalculation($internalTotal, $squareResponse);

        // Assert that our calculation matches Square's
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

    // ========================================================================
    // Service charge combination validation against Square's CalculateOrder API
    // ========================================================================

    /**
     * Helper: create an order with products, attach a service charge, and validate against Square.
     */
    private function assertServiceChargeMatchesSquare(array $serviceChargeAttrs, string $scope = Constants::DEDUCTIBLE_SCOPE_ORDER): void
    {
        $order = factory(Order::class)->create();
        $product1 = factory(Product::class)->create(['price' => 15_00]);
        $product2 = factory(Product::class)->create(['price' => 50_00]);

        Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($product1, 2) // $30.00
            ->addProduct($product2, 1) // $50.00
            ->save();

        $serviceCharge = factory(ServiceCharge::class)->create($serviceChargeAttrs);

        if ($scope === Constants::DEDUCTIBLE_SCOPE_PRODUCT) {
            // PRODUCT-scoped service charges must be attached to a specific line item's pivot
            $order->products->first()->pivot->serviceCharges()->attach($serviceCharge->id, [
                'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
                'featurable_type' => Constants::ORDER_PRODUCT_NAMESPACE,
                'scope'           => $scope,
            ]);
        } else {
            $order->serviceCharges()->attach($serviceCharge->id, [
                'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
                'featurable_type' => config('nikolag.connections.square.order.namespace'),
                'scope'           => $scope,
            ]);
        }

        $order->refresh();
        $order->load('products', 'taxes', 'discounts', 'serviceCharges', 'fulfillments');

        $internalTotal = OrderCalculator::calculateTotalOrderCostByModel($order);
        $squareResponse = Square::calculateOrder($order, env('SQUARE_LOCATION'));
        $comparison = $this->compareWithSquareCalculation($internalTotal, $squareResponse);

        $this->assertTrue(
            $comparison['matches'],
            sprintf(
                '[%s] Internal=%d, Square=%d, Diff=%d. Square: tax=%d, discount=%d, sc=%d',
                $serviceChargeAttrs['name'] ?? 'unnamed',
                $comparison['internal_total'],
                $comparison['square_total'],
                $comparison['difference'],
                $comparison['square_breakdown']['total_tax_money'] ?? 0,
                $comparison['square_breakdown']['total_discount_money'] ?? 0,
                $comparison['square_breakdown']['total_service_charge_money'] ?? 0
            )
        );
    }

    /**
     * SUBTOTAL_PHASE + LINE_ITEM_TREATMENT + fixed amount (ORDER scope)
     *
     * @group live
     */
    public function test_sc_subtotal_line_item_fixed_order(): void
    {
        $this->assertServiceChargeMatchesSquare([
            'name'              => 'Subtotal/LineItem/Fixed/Order',
            'amount_money'      => 5_00,
            'calculation_phase' => OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE,
            'treatment_type'    => OrderServiceChargeTreatmentType::LINE_ITEM_TREATMENT,
            'taxable'           => false,
        ]);
    }

    /**
     * SUBTOTAL_PHASE + LINE_ITEM_TREATMENT + percentage (ORDER scope)
     *
     * @group live
     */
    public function test_sc_subtotal_line_item_percentage_order(): void
    {
        $this->assertServiceChargeMatchesSquare([
            'name'              => 'Subtotal/LineItem/Percentage/Order',
            'percentage'        => 10.0,
            'calculation_phase' => OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE,
            'treatment_type'    => OrderServiceChargeTreatmentType::LINE_ITEM_TREATMENT,
            'taxable'           => false,
        ]);
    }

    /**
     * APPORTIONED_AMOUNT_PHASE + APPORTIONED_TREATMENT + fixed amount (ORDER scope)
     *
     * @group live
     */
    public function test_sc_apportioned_amount_order(): void
    {
        $this->assertServiceChargeMatchesSquare([
            'name'              => 'ApportionedAmount/Order',
            'amount_money'      => 10_00,
            'calculation_phase' => OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE,
            'treatment_type'    => OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT,
            'taxable'           => false,
        ]);
    }

    /**
     * APPORTIONED_PERCENTAGE_PHASE + APPORTIONED_TREATMENT + percentage (ORDER scope)
     *
     * @group live
     */
    public function test_sc_apportioned_percentage_order(): void
    {
        $this->assertServiceChargeMatchesSquare([
            'name'              => 'ApportionedPercentage/Order',
            'percentage'        => 8.0,
            'calculation_phase' => OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE,
            'treatment_type'    => OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT,
            'taxable'           => false,
        ]);
    }

    /**
     * APPORTIONED_AMOUNT_PHASE + APPORTIONED_TREATMENT + fixed amount (LINE_ITEM scope)
     *
     * @group live
     */
    public function test_sc_apportioned_amount_line_item(): void
    {
        $this->assertServiceChargeMatchesSquare([
            'name'              => 'ApportionedAmount/LineItem',
            'amount_money'      => 10_00,
            'calculation_phase' => OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE,
            'treatment_type'    => OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT,
            'taxable'           => false,
        ], Constants::DEDUCTIBLE_SCOPE_PRODUCT);
    }

    /**
     * APPORTIONED_PERCENTAGE_PHASE + APPORTIONED_TREATMENT + percentage (LINE_ITEM scope)
     *
     * @group live
     */
    public function test_sc_apportioned_percentage_line_item(): void
    {
        $this->assertServiceChargeMatchesSquare([
            'name'              => 'ApportionedPercentage/LineItem',
            'percentage'        => 8.0,
            'calculation_phase' => OrderServiceChargeCalculationPhase::APPORTIONED_PERCENTAGE_PHASE,
            'treatment_type'    => OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT,
            'taxable'           => false,
        ], Constants::DEDUCTIBLE_SCOPE_PRODUCT);
    }

    /**
     * SUBTOTAL_PHASE + LINE_ITEM_TREATMENT + taxable fixed amount (ORDER scope)
     *
     * @group live
     */
    public function test_sc_subtotal_taxable_fixed_order(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create(['price' => 100_00]);

        Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($product, 1)
            ->save();

        $tax = factory(Tax::class)->create([
            'percentage' => 10.0,
            'type'       => Constants::TAX_ADDITIVE,
        ]);

        $serviceCharge = factory(ServiceCharge::class)->create([
            'name'              => 'Taxable Subtotal Fee',
            'amount_money'      => 5_00,
            'calculation_phase' => OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE,
            'treatment_type'    => OrderServiceChargeTreatmentType::LINE_ITEM_TREATMENT,
            'taxable'           => true,
        ]);

        $order->taxes()->attach($tax->id, [
            'deductible_type' => Constants::TAX_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);
        $order->serviceCharges()->attach($serviceCharge->id, [
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        $order->refresh();
        $order->load('products', 'taxes', 'discounts', 'serviceCharges', 'fulfillments');

        $internalTotal = OrderCalculator::calculateTotalOrderCostByModel($order);
        $squareResponse = Square::calculateOrder($order, env('SQUARE_LOCATION'));
        $comparison = $this->compareWithSquareCalculation($internalTotal, $squareResponse);

        $this->assertTrue(
            $comparison['matches'],
            sprintf(
                'Taxable SC: Internal=%d, Square=%d, Diff=%d. Square: tax=%d, sc=%d',
                $comparison['internal_total'],
                $comparison['square_total'],
                $comparison['difference'],
                $comparison['square_breakdown']['total_tax_money'] ?? 0,
                $comparison['square_breakdown']['total_service_charge_money'] ?? 0
            )
        );
    }
}
