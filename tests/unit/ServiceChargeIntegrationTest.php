<?php

namespace Nikolag\Square\Tests\Unit;

use Illuminate\Validation\ValidationException;
use Nikolag\Square\Models\Discount;
use Nikolag\Square\Models\ServiceCharge;
use Nikolag\Square\Models\Tax;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Utils\Constants;
use Nikolag\Square\Utils\Util;
use Nikolag\Square\Facades\Square;
use Nikolag\Square\Models\Product as ModelsProduct;

class ServiceChargeIntegrationTest extends TestCase
{
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
            'name' => 'Processing Fee',
            'percentage' => 2.5,
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
            'scope' => Constants::DEDUCTIBLE_SCOPE_ORDER
        ]);

        $order->refresh();

        // Expected: 3 × $15.00 = $45.00, service charge 2.5% = $1.13, total = $46.13 = 4613 cents
        $expectedTotal = 4613;
        $actualTotal = Util::calculateTotalOrderCostByModel($order);

        $this->assertEquals($expectedTotal, $actualTotal, 
            'Service charge should work correctly with variable pricing');
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
            'name' => 'Service Fee',
            'percentage' => 5.0,
        ]);

        $serviceCharge2 = factory(ServiceCharge::class)->create([
            'name' => 'Processing Fee',
            'amount_money' => 100, // $1.00
            'amount_currency' => 'USD',
        ]);

        $square = Square::setOrder($order, env('SQUARE_LOCATION'))
            ->addProduct($product, 2) // 2 × $10.00 = $20.00
            ->save();

        // Attach both service charges
        $order->serviceCharges()->attach($serviceCharge1->id, [
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope' => Constants::DEDUCTIBLE_SCOPE_ORDER
        ]);

        $order->serviceCharges()->attach($serviceCharge2->id, [
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'featurable_type' => config('nikolag.connections.square.order.namespace'),
            'scope' => Constants::DEDUCTIBLE_SCOPE_ORDER
        ]);

        $order->refresh();
        $order->load('serviceCharges');

        $this->assertCount(2, $order->serviceCharges, 'Order should have 2 service charges');

        // Calculate total: $20.00 + 5% ($1.00) + $1.00 = $22.00 = 2200 cents
        $expectedTotal = 2200;
        $actualTotal = Util::calculateTotalOrderCostByModel($order);

        $this->assertEquals($expectedTotal, $actualTotal, 'Multiple service charges were not calculated correctly');
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
            'name' => 'Handling Fee',
            'amount_money' => 1_50, // $1.50
            'amount_currency' => 'USD',
            'calculation_phase' => Constants::SERVICE_CHARGE_CALCULATION_PHASE_SUBTOTAL,
            'treatment_type' => Constants::SERVICE_CHARGE_TREATMENT_LINE_ITEM,
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
            'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Service charge calculation phase "SUBTOTAL" cannot be applied to products in an order');

        Util::calculateTotalOrderCostByModel($order);
    }
}
