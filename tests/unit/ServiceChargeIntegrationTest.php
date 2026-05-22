<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Exceptions\InvalidSquareOrderException;
use Nikolag\Square\Facades\Square;
use Nikolag\Square\Models\Discount;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Models\Product as ModelsProduct;
use Nikolag\Square\Models\ServiceCharge;
use Nikolag\Square\Models\Tax;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Tests\Traits\AssertsSquareCalculation;
use Nikolag\Square\Utils\Constants;
use Nikolag\Square\Utils\OrderCalculator;
use Square\Models\OrderServiceChargeCalculationPhase;
use Square\Models\OrderServiceChargeTreatmentType;

class ServiceChargeIntegrationTest extends TestCase
{
    use AssertsSquareCalculation;

    /**
     * Test service charge integration with full order processing workflow.
     *
     * @return void
     */
    public function test_service_charge_integration_with_full_order_workflow(): void
    {
        // Create test data
        $order = factory(Order::class)->create();
        $product1 = factory(ModelsProduct::class)->create(['price' => 10_00]); // $10.00
        $product2 = factory(Product::class)->create(['price' => 20_00]); // $20.00

        // Create service charges
        $orderServiceCharge = factory(ServiceCharge::class)->create([
            'name'              => 'Handling Fee',
            'amount_money'      => 5_00, // $5.00
            'calculation_phase' => OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE,
            'treatment_type'    => OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT,
            'taxable'           => false,
        ]);

        $productServiceCharge = factory(ServiceCharge::class)->create([
            'name'              => 'Fake Percentage Fee',
            'percentage'        => 5.0,
            'treatment_type'    => OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT,
            'calculation_phase' => OrderServiceChargeCalculationPhase::TOTAL_PHASE,
            'taxable'           => false,
        ]);

        // Create tax and discount for comprehensive testing
        $tax = factory(Tax::class)->create([
            'percentage' => 8.5,
            'type'       => Constants::TAX_ADDITIVE,
        ]);

        $discount = factory(Discount::class)->create([
            'percentage' => 10.0,
            'amount'     => null,
        ]);

        // Build order through Square service
        $square = Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($product1, 2) // 2 × $10.00 = $20.00
            ->addProduct($product2, 1) // 1 × $20.00 = $20.00
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
        $order->load('products', 'serviceCharges', 'taxes', 'discounts');

        // Verify service charges are attached
        $this->assertCount(1, $order->serviceCharges, 'Order should have 1 service charge');
        $this->assertEquals('Handling Fee', $order->serviceCharges->first()->name);

        $this->assertCount(1, $order->products->first()->pivot->serviceCharges, 'First product should have 1 service charge');
        $this->assertEquals('Fake Percentage Fee', $order->products->first()->pivot->serviceCharges->first()->name);

        // Per-line-item calculation (2 line items, each with ratio 0.5):
        //
        // Order-level totals: subtotal=$40, discount=$4.00 (10%), SC=$5+5%
        //
        // Each line item (apportioned by 0.5 ratio):
        //   Base: $20.00 (line item 1: 2×$10, line item 2: 1×$20)
        //   Discount: -$2.00 (10% of $20.00, order total: $4.00) → $18.00
        //   Subtotal SC: +$2.50 ($5.00 × 0.5 ratio, order total: $5.00) → $20.50
        //   Tax base: $18.00 (non-taxable SCs excluded from tax base)
        //   Total SC: +$1.02 (5% of $20.50, bankers rounded)
        //   Running total: $20.50 + $1.02 = $21.52
        //   Tax: $1.53 (8.5% of $18.00)
        //   Line item total: $21.52 + $1.53 = $23.05
        //
        // Order total: $23.05 × 2 = $46.10
        $actualTotal = OrderCalculator::calculateTotalOrderCostByModel($order);

        $this->assertEquals(46_10, $actualTotal, 'Total calculation should include all service charges, taxes, and discounts');

        $this->validateAgainstSquareApi($order, $actualTotal);

        // Test building Square request with service charges
        $squareBuilder = Square::getSquareBuilder();

        // Build service charges
        $serviceCharges = $squareBuilder->buildServiceCharges($order->serviceCharges, 'USD');
        $this->assertCount(1, $serviceCharges, 'Should build 1 order-level service charge');

        // Build products with service charges
        $products = $squareBuilder->buildProducts($order->products, 'USD');
        $this->assertCount(2, $products, 'Should build 2 products');

        // Verify first product has applied service charges
        $firstProduct = $products[0];
        $appliedServiceCharges = $firstProduct->getAppliedServiceCharges();
        $this->assertCount(1, $appliedServiceCharges, 'First product should have 1 applied service charge');
    }

    /**
     * Test service charge with variable pricing support.
     *
     * @return void
     */
    public function test_service_charge_with_variable_pricing(): void
    {
        $order = factory(Order::class)->create();

        // Create product with null price (variable pricing)
        $variableProduct = factory(Product::class)->create(['price' => null]);

        $serviceCharge = factory(ServiceCharge::class)->create([
            'name'         => 'Processing Fee',
            'percentage'   => 2.5,
            'amount_money' => null,
        ]);

        // Add product with custom price through Square service
        $variableProduct->price = 1500; // $15.00 custom price

        $square = Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($variableProduct, 3)
            ->save();

        // Attach service charge
        $order->serviceCharges()->attach($serviceCharge->id, [
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        $order->refresh();

        // Expected: 3 × $15.00 = $45.00, service charge 2.5% = $1.125 → $1.12 (bankers rounding), total = $46.12
        $expectedTotal = 4612;
        $actualTotal = OrderCalculator::calculateTotalOrderCostByModel($order);

        $this->assertEquals(
            $expectedTotal,
            $actualTotal,
            'Service charge should work correctly with variable pricing'
        );

        $this->validateAgainstSquareApi($order, $actualTotal);
    }

    /**
     * Test service charge integration with order charging.
     *
     * @return void
     */
    public function test_service_charge_integration_with_order_charge(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create(['price' => 2000]); // $20.00

        $serviceCharge = factory(ServiceCharge::class)->create([
            'name'            => 'Service Fee',
            'amount_money'    => 300, // $3.00
            'amount_currency' => 'USD',
            'percentage'      => null,
        ]);

        // Add a service charge to the order
        $order->serviceCharges()->attach($serviceCharge->id, [
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        // Build order with service charge
        $square = Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($product, 1)
            ->save();

        $order->refresh();

        // Calculate total: $20.00 + $3.00 = $23.00 = 2300 cents
        $expectedTotal = 2300;
        $actualTotal = OrderCalculator::calculateTotalOrderCostByModel($order);

        $this->assertEquals($expectedTotal, $actualTotal);

        $this->validateAgainstSquareApi($order, $actualTotal);

        // Test charging the order
        $transaction = $square->charge([
            'amount'      => $expectedTotal,
            'source_id'   => 'cnon:card-nonce-ok',
            'location_id' => env('SQUARE_LOCATION'),
        ]);

        $this->assertNotNull($transaction, 'Transaction should be created successfully');
        $this->assertEquals($expectedTotal, $transaction->amount, 'Transaction amount should match calculated total');
    }

    /**
     * Test service charge integration with product charging.
     *
     * @return void
     */
    public function test_service_charge_integration_with_product_charge(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create(['price' => 1500]); // $15.00
        $order->attachProduct($product, ['quantity' => 2]);

        // Create a service charge with apportioned amount calculation
        $serviceCharge = factory(ServiceCharge::class)->create([
            'name'              => 'Fixed amount service charge',
            'amount_money'      => 10_00, // 10.00 USD
            'calculation_phase' => OrderServiceChargeCalculationPhase::APPORTIONED_AMOUNT_PHASE,
            'taxable'           => true,
            'treatment_type'    => OrderServiceChargeTreatmentType::APPORTIONED_TREATMENT,
        ]);

        // Add a service charge to the product
        $order->products->first()->pivot->serviceCharges()->attach($serviceCharge->id, [
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'featurable_type' => Constants::ORDER_PRODUCT_NAMESPACE,
            'scope'           => Constants::DEDUCTIBLE_SCOPE_PRODUCT,
        ]);

        // Build order with service charge
        $square = Square::setOrder($order, env('SQUARE_LOCATION'))->save();

        // Calculate total: ($15.00 + $10.00) x 2 = $50.00
        $expectedTotal = 50_00;
        $actualTotal = OrderCalculator::calculateTotalOrderCostByModel($square->getOrder());

        $this->assertEquals($expectedTotal, $actualTotal);

        $this->validateAgainstSquareApi($square->getOrder(), $actualTotal);

        // Test charging the order
        $transaction = $square->charge([
            'amount'      => $expectedTotal,
            'source_id'   => 'cnon:card-nonce-ok',
            'location_id' => env('SQUARE_LOCATION'),
        ]);

        $this->assertNotNull($transaction, 'Transaction should be created successfully');
        $this->assertEquals($expectedTotal, $transaction->amount, 'Transaction amount should match calculated total');
    }

    /**
     * Test multiple service charges on the same order.
     *
     * @return void
     */
    public function test_multiple_service_charges_integration(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create(['price' => 1000]); // $10.00

        // Create multiple service charges
        $serviceCharge1 = factory(ServiceCharge::class)->create([
            'name'       => 'Service Fee',
            'percentage' => 5.0,
        ]);

        $serviceCharge2 = factory(ServiceCharge::class)->create([
            'name'            => 'Processing Fee',
            'amount_money'    => 100, // $1.00
            'amount_currency' => 'USD',
        ]);

        $square = Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($product, 2) // 2 × $10.00 = $20.00
            ->save();

        // Attach both service charges
        $order->serviceCharges()->attach($serviceCharge1->id, [
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        $order->serviceCharges()->attach($serviceCharge2->id, [
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        $order->refresh();
        $order->load('serviceCharges');

        $this->assertCount(2, $order->serviceCharges, 'Order should have 2 service charges');

        // Calculate total: $20.00 + 5% ($1.00) + $1.00 = $22.00 = 2200 cents
        $expectedTotal = 2200;
        $actualTotal = OrderCalculator::calculateTotalOrderCostByModel($order);

        $this->assertEquals($expectedTotal, $actualTotal, 'Multiple service charges were not calculated correctly');

        $this->validateAgainstSquareApi($order, $actualTotal);
    }

    /**
     * Test multiple service charges on the same order.
     *
     * @return void
     */
    public function test_total_service_charge_cannot_be_applied_to_products(): void
    {
        // Create test data
        $order = factory(Order::class)->create();
        $product1 = factory(ModelsProduct::class)->create(['price' => 1000]); // $10.00
        $product2 = factory(Product::class)->create(['price' => 2000]); // $20.00

        // Create a service charge to be applied to a product within the order
        $productServiceCharge = factory(ServiceCharge::class)->create([
            'name'              => 'Handling Fee',
            'amount_money'      => 1_50, // $1.50
            'amount_currency'   => 'USD',
            'calculation_phase' => OrderServiceChargeCalculationPhase::SUBTOTAL_PHASE,
            'treatment_type'    => OrderServiceChargeTreatmentType::LINE_ITEM_TREATMENT,
        ]);

        // Build order through Square service
        $square = Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($product1, 2) // 2 × $10.00 = $20.00
            ->addProduct($product2, 1) // 1 × $20.00 = $20.00
            ->save();

        // Attach product-level service charge to first product
        $order->products->first()->pivot->serviceCharges()->attach($productServiceCharge->id, [
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'featurable_type' => Constants::ORDER_PRODUCT_NAMESPACE,
            'scope'           => Constants::DEDUCTIBLE_SCOPE_PRODUCT,
        ]);

        $this->expectException(InvalidSquareOrderException::class);
        $this->expectExceptionMessage('Service charge calculation phase "SUBTOTAL" cannot be applied to products in an order');

        OrderCalculator::calculateTotalOrderCostByModel($order);
    }
}
