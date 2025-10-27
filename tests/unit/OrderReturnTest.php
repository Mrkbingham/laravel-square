<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Models\Discount;
use Nikolag\Square\Models\OrderReturn;
use Nikolag\Square\Models\OrderReturnLineItem;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Models\ServiceCharge;
use Nikolag\Square\Models\Tax;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Tests\TestDataHolder;
use Nikolag\Square\Utils\Constants;

class OrderReturnTest extends TestCase
{
    private TestDataHolder $data;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->data = TestDataHolder::create();
    }

    /**
     * Test OrderReturn creation and basic properties.
     *
     * @return void
     */
    public function test_order_return_creation(): void
    {
        $testUUID = fake()->unique->uuid;
        $order = factory(Order::class)->create([
            'payment_service_id' => $testUUID,
        ]);

        /** @var OrderReturn */
        $orderReturn = factory(OrderReturn::class)->make([
            'source_order_id' => $testUUID,
            'uid' => 'test-return-uid-123',
            'data' => $this->data->squareOrderReturn,
        ]);

        $this->assertNotNull($orderReturn);
        $this->assertEquals($order->payment_service_id, $orderReturn->source_order_id);
        $this->assertEquals('test-return-uid-123', $orderReturn->uid);
        $this->assertEquals(20_00, $orderReturn->data->getReturnAmounts()->getTotalMoney()->getAmount());
        $this->assertEquals('USD', $orderReturn->data->getReturnAmounts()->getTotalMoney()->getCurrency());
        $this->assertEquals(2_00, $orderReturn->data->getReturnAmounts()->getTaxMoney()->getAmount());
        $this->assertEquals('USD', $orderReturn->data->getReturnAmounts()->getTaxMoney()->getCurrency());
        $this->assertEquals(1_00, $orderReturn->data->getReturnAmounts()->getDiscountMoney()->getAmount());
        $this->assertEquals('USD', $orderReturn->data->getReturnAmounts()->getDiscountMoney()->getCurrency());
        $this->assertEquals(1_50, $orderReturn->data->getReturnAmounts()->getTipMoney()->getAmount());
        $this->assertEquals('USD', $orderReturn->data->getReturnAmounts()->getTipMoney()->getCurrency());
        $this->assertEquals(50, $orderReturn->data->getReturnAmounts()->getServiceChargeMoney()->getAmount());
        $this->assertEquals('USD', $orderReturn->data->getReturnAmounts()->getServiceChargeMoney()->getCurrency());
    }

    /**
     * Test OrderReturn relationships with Order.
     *
     * @return void
     */
    public function test_order_return_relationship_with_order(): void
    {
        $testUUID = fake()->unique->uuid;
        $order = factory(Order::class)->create([
            'payment_service_id' => $testUUID,
        ]);

        /** @var OrderReturn */
        $orderReturn = factory(OrderReturn::class)->create([
            'source_order_id' => $testUUID,
            'data' => $this->data->squareOrderReturn,
        ]);

        $this->assertInstanceOf(Order::class, $orderReturn->order);
        $this->assertEquals($order->payment_service_id, $orderReturn->order->payment_service_id);
    }

    /**
     * Test OrderReturn relationships with line items through HasReturnLineItems trait.
     *
     * @return void
     */
    public function test_order_return_has_line_items_relationship(): void
    {
        $testUUID = fake()->unique->uuid;
        $order = factory(Order::class)->create([
            'payment_service_id' => $testUUID,
        ]);

        /** @var OrderReturn */
        $orderReturn = factory(OrderReturn::class)->create([
            'source_order_id' => $testUUID,
            'data' => $this->data->squareOrderReturn,
        ]);

        /** @var Product */
        $product1 = factory(Product::class)->create(['price' => 10_00]);
        $product2 = factory(Product::class)->create(['price' => 15_00]);

        $lineItem1 = factory(OrderReturnLineItem::class)->create([
            'order_return_id' => $orderReturn->id,
            'product_id' => $product1->id,
            'quantity' => 1,
        ]);

        $lineItem2 = factory(OrderReturnLineItem::class)->create([
            'order_return_id' => $orderReturn->id,
            'product_id' => $product2->id,
            'quantity' => 2,
        ]);

        $this->assertCount(2, $orderReturn->returnLineItems);
        $this->assertContainsOnlyInstancesOf(OrderReturnLineItem::class, $orderReturn->returnLineItems);
    }

    /**
     * Test OrderReturn attachReturnLineItem method.
     *
     * @return void
     */
    public function test_order_return_attach_line_item(): void
    {
        $testUUID = fake()->unique->uuid;
        $order = factory(Order::class)->create([
            'payment_service_id' => $testUUID,
        ]);

        /** @var OrderReturn */
        $orderReturn = factory(OrderReturn::class)->create([
            'source_order_id' => $testUUID,
            'data' => $this->data->squareOrderReturn,
        ]);

        /** @var Product */
        $product = factory(Product::class)->create(['price' => 25_00]);

        $lineItem = $orderReturn->attachReturnLineItem($product, [
            'quantity' => 3,
            'note' => 'Test return line item',
        ]);

        $this->assertInstanceOf(OrderReturnLineItem::class, $lineItem);
        $this->assertEquals($orderReturn->id, $lineItem->order_return_id);
        $this->assertEquals($product->id, $lineItem->product_id);
        $this->assertEquals(3, $lineItem->quantity);
        $this->assertEquals('Test return line item', $lineItem->note);
        $this->assertEquals(25_00, $lineItem->base_price_money_amount);
    }

    /**
     * Test OrderReturn hasProduct method through line items.
     *
     * @return void
     */
    public function test_order_return_has_product_through_line_items(): void
    {
        $testUUID = fake()->unique->uuid;
        $order = factory(Order::class)->create([
            'payment_service_id' => $testUUID,
        ]);

        /** @var OrderReturn */
        $orderReturn = factory(OrderReturn::class)->create([
            'source_order_id' => $testUUID,
            'data' => $this->data->squareOrderReturn,
        ]);

        /** @var Product */
        $product = factory(Product::class)->create();

        $orderReturn->attachReturnLineItem($product);

        $this->assertTrue($orderReturn->hasProduct($product));
        $this->assertTrue($orderReturn->hasProduct(['id' => $product->id]));
    }

    /**
     * Test OrderReturn products() method.
     *
     * @return void
     */
    public function test_order_return_products_collection(): void
    {
        $testUUID = fake()->unique->uuid;
        $order = factory(Order::class)->create([
            'payment_service_id' => $testUUID,
        ]);

        /** @var OrderReturn */
        $orderReturn = factory(OrderReturn::class)->create([
            'source_order_id' => $testUUID,
            'data' => $this->data->squareOrderReturn,
        ]);

        /** @var Product */
        $product1 = factory(Product::class)->create(['name' => 'Product 1']);
        $product2 = factory(Product::class)->create(['name' => 'Product 2']);

        $orderReturn->attachReturnLineItem($product1);
        $orderReturn->attachReturnLineItem($product2);

        $products = $orderReturn->products();
        $this->assertCount(2, $products);
        $this->assertContainsOnlyInstancesOf(Product::class, $products);
    }

    /**
     * Test OrderReturn hasReturnLineItem method.
     *
     * @return void
     */
    public function test_order_return_has_return_line_item(): void
    {
        $testUUID = fake()->unique->uuid;
        $order = factory(Order::class)->create([
            'payment_service_id' => $testUUID,
        ]);

        /** @var OrderReturn */
        $orderReturn = factory(OrderReturn::class)->create([
            'source_order_id' => $testUUID,
            'data' => $this->data->squareOrderReturn,
        ]);

        /** @var Product */
        $product = factory(Product::class)->create();

        $lineItem = $orderReturn->attachReturnLineItem($product);

        $this->assertTrue($orderReturn->hasReturnLineItem($lineItem));
        $this->assertTrue($orderReturn->hasReturnLineItem(['id' => $lineItem->id]));
    }

    /**
     * Test OrderReturn with taxes through line items.
     *
     * @return void
     */
    public function test_order_return_has_tax_through_line_items(): void
    {
        $testUUID = fake()->unique->uuid;
        $order = factory(Order::class)->create([
            'payment_service_id' => $testUUID,
        ]);

        /** @var OrderReturn */
        $orderReturn = factory(OrderReturn::class)->create([
            'source_order_id' => $testUUID,
            'data' => $this->data->squareOrderReturn,
        ]);

        /** @var Product */
        $product = factory(Product::class)->create();

        $lineItem = $orderReturn->attachReturnLineItem($product);

        /** @var Tax */
        $tax = factory(Tax::class)->create(['percentage' => 8.5]);

        $lineItem->taxes()->attach($tax->id, [
            'featurable_type' => Constants::ORDER_RETURN_LINE_ITEM_NAMESPACE,
            'deductible_type' => Constants::TAX_NAMESPACE,
            'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT
        ]);

        $this->assertTrue($orderReturn->hasTax($tax));
        $this->assertCount(1, $orderReturn->taxes());
    }

    /**
     * Test OrderReturn with discounts through line items.
     *
     * @return void
     */
    public function test_order_return_has_discount_through_line_items(): void
    {
        $testUUID = fake()->unique->uuid;
        $order = factory(Order::class)->create([
            'payment_service_id' => $testUUID,
        ]);

        /** @var OrderReturn */
        $orderReturn = factory(OrderReturn::class)->create([
            'source_order_id' => $testUUID,
            'data' => $this->data->squareOrderReturn,
        ]);

        /** @var Product */
        $product = factory(Product::class)->create();

        $lineItem = $orderReturn->attachReturnLineItem($product);

        /** @var Discount */
        $discount = factory(Discount::class)->states('PERCENTAGE_ONLY')->create(['percentage' => 10.0]);

        $lineItem->discounts()->attach($discount->id, [
            'featurable_type' => Constants::ORDER_RETURN_LINE_ITEM_NAMESPACE,
            'deductible_type' => Constants::DISCOUNT_NAMESPACE,
            'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT
        ]);

        $this->assertTrue($orderReturn->hasDiscount($discount));
        $this->assertCount(1, $orderReturn->discounts());
    }

    /**
     * Test OrderReturn with service charges through line items.
     *
     * @return void
     */
    public function test_order_return_has_service_charge_through_line_items(): void
    {
        $testUUID = fake()->unique->uuid;
        $order = factory(Order::class)->create([
            'payment_service_id' => $testUUID,
        ]);

        /** @var OrderReturn */
        $orderReturn = factory(OrderReturn::class)->create([
            'source_order_id' => $testUUID,
            'data' => $this->data->squareOrderReturn,
        ]);

        /** @var Product */
        $product = factory(Product::class)->create();

        $lineItem = $orderReturn->attachReturnLineItem($product);

        /** @var ServiceCharge */
        $serviceCharge = factory(ServiceCharge::class)->states('AMOUNT_ONLY')->create(['amount_money' => 2_00]);

        $lineItem->serviceCharges()->attach($serviceCharge->id, [
            'featurable_type' => Constants::ORDER_RETURN_LINE_ITEM_NAMESPACE,
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT
        ]);

        $this->assertTrue($orderReturn->hasServiceCharge($serviceCharge));
        $this->assertCount(1, $orderReturn->serviceCharges());
    }
}
