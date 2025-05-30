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
}
