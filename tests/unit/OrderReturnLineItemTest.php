<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Models\Discount;
use Nikolag\Square\Models\OrderReturn;
use Nikolag\Square\Models\OrderReturnLineItemPivot;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Models\ServiceCharge;
use Nikolag\Square\Models\Tax;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Tests\TestDataHolder;
use Nikolag\Square\Utils\Constants;

class OrderReturnLineItemTest extends TestCase
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
     * Test OrderReturnLineItemPivot creation and basic properties.
     *
     * @return void
     */
    public function test_order_return_line_item_creation(): void
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
        $product = factory(Product::class)->create([
            'price' => 15_00,
        ]);

        /** @var OrderReturnLineItemPivot */
        $lineItem = factory(OrderReturnLineItemPivot::class)->create([
            'order_return_id' => $orderReturn->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'base_price_money_amount' => 15_00,
            'gross_return_money_amount' => 30_00,
        ]);

        $this->assertNotNull($lineItem);
        $this->assertEquals($orderReturn->id, $lineItem->order_return_id);
        $this->assertEquals($product->id, $lineItem->product_id);
        $this->assertEquals(2, $lineItem->quantity);
        $this->assertEquals(15_00, $lineItem->base_price_money_amount);
        $this->assertEquals(30_00, $lineItem->gross_return_money_amount);
        $this->assertEquals('USD', $lineItem->base_price_money_currency);
    }

    /**
     * Test OrderReturnLineItemPivot relationships with OrderReturn.
     *
     * @return void
     */
    public function test_order_return_line_item_relationship_with_order_return(): void
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

        /** @var OrderReturnLineItemPivot */
        $lineItem = factory(OrderReturnLineItemPivot::class)->create([
            'order_return_id' => $orderReturn->id,
            'product_id' => $product->id,
        ]);

        $this->assertInstanceOf(OrderReturn::class, $lineItem->orderReturn);
        $this->assertEquals($orderReturn->id, $lineItem->orderReturn->id);
    }

    /**
     * Test OrderReturnLineItemPivot relationships with Product.
     *
     * @return void
     */
    public function test_order_return_line_item_relationship_with_product(): void
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

        /** @var OrderReturnLineItemPivot */
        $lineItem = factory(OrderReturnLineItemPivot::class)->create([
            'order_return_id' => $orderReturn->id,
            'product_id' => $product->id,
        ]);

        $this->assertInstanceOf(Product::class, $lineItem->product);
        $this->assertEquals($product->id, $lineItem->product->id);
    }

    /**
     * Test OrderReturnLineItemPivot with taxes.
     *
     * @return void
     */
    public function test_order_return_line_item_with_taxes(): void
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

        /** @var OrderReturnLineItemPivot */
        $lineItem = factory(OrderReturnLineItemPivot::class)->create([
            'order_return_id' => $orderReturn->id,
            'product_id' => $product->id,
        ]);

        /** @var Tax */
        $tax = factory(Tax::class)->create(['percentage' => 8.5]);

        $lineItem->taxes()->attach($tax->id, [
            'featurable_type' => Constants::ORDER_RETURN_LINE_ITEM_NAMESPACE,
            'deductible_type' => Constants::TAX_NAMESPACE,
            'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT
        ]);

        $this->assertNotNull($lineItem->taxes);
        $this->assertCount(1, $lineItem->taxes);
        $this->assertTrue($lineItem->hasTax($tax));
    }

    /**
     * Test OrderReturnLineItemPivot with discounts.
     *
     * @return void
     */
    public function test_order_return_line_item_with_discounts(): void
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

        /** @var OrderReturnLineItemPivot */
        $lineItem = factory(OrderReturnLineItemPivot::class)->create([
            'order_return_id' => $orderReturn->id,
            'product_id' => $product->id,
        ]);

        /** @var Discount */
        $discount = factory(Discount::class)->states('PERCENTAGE_ONLY')->create(['percentage' => 10.0]);

        $lineItem->discounts()->attach($discount->id, [
            'featurable_type' => Constants::ORDER_RETURN_LINE_ITEM_NAMESPACE,
            'deductible_type' => Constants::DISCOUNT_NAMESPACE,
            'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT
        ]);

        $this->assertNotNull($lineItem->discounts);
        $this->assertCount(1, $lineItem->discounts);
        $this->assertTrue($lineItem->hasDiscount($discount));
    }

    /**
     * Test OrderReturnLineItemPivot with service charges.
     *
     * @return void
     */
    public function test_order_return_line_item_with_service_charges(): void
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

        /** @var OrderReturnLineItemPivot */
        $lineItem = factory(OrderReturnLineItemPivot::class)->create([
            'order_return_id' => $orderReturn->id,
            'product_id' => $product->id,
        ]);

        /** @var ServiceCharge */
        $serviceCharge = factory(ServiceCharge::class)->states('AMOUNT_ONLY')->create(['amount_money' => 2_00]);

        $lineItem->serviceCharges()->attach($serviceCharge->id, [
            'featurable_type' => Constants::ORDER_RETURN_LINE_ITEM_NAMESPACE,
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT
        ]);

        $this->assertNotNull($lineItem->serviceCharges);
        $this->assertCount(1, $lineItem->serviceCharges);
        $this->assertTrue($lineItem->hasServiceCharge($serviceCharge));
    }

    /**
     * Test OrderReturnLineItemPivot hasProduct method.
     *
     * @return void
     */
    public function test_order_return_line_item_has_product(): void
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

        /** @var OrderReturnLineItemPivot */
        $lineItem = factory(OrderReturnLineItemPivot::class)->create([
            'order_return_id' => $orderReturn->id,
            'product_id' => $product->id,
        ]);

        $this->assertTrue($lineItem->hasProduct($product));
        $this->assertTrue($lineItem->hasProduct(['id' => $product->id]));
        $this->assertTrue($lineItem->hasProduct($product->id));
    }

    /**
     * Test factory creation with all fields.
     *
     * @return void
     */
    public function test_factory_creation_with_all_fields(): void
    {
        /** @var OrderReturnLineItemPivot */
        $lineItem = factory(OrderReturnLineItemPivot::class)->create([
            'quantity' => 3,
            'square_uid' => 'test-square-uid',
            'source_line_item_uid' => 'test-source-line-item-uid',
            'catalog_object_id' => 'test-catalog-object-id',
            'catalog_version' => 5,
            'variation_name' => 'Large Size',
            'item_type' => 'ITEM',
            'note' => 'Special return instructions',
            'base_price_money_amount' => 20_00,
            'variation_total_price_money_amount' => 60_00,
            'gross_return_money_amount' => 60_00,
            'total_discount_money_amount' => 5_00,
            'total_service_charge_money_amount' => 2_00,
        ]);

        $this->assertNotNull($lineItem);
        $this->assertEquals(3, $lineItem->quantity);
        $this->assertEquals('test-square-uid', $lineItem->square_uid);
        $this->assertEquals('test-source-line-item-uid', $lineItem->source_line_item_uid);
        $this->assertEquals('test-catalog-object-id', $lineItem->catalog_object_id);
        $this->assertEquals(5, $lineItem->catalog_version);
        $this->assertEquals('Large Size', $lineItem->variation_name);
        $this->assertEquals('ITEM', $lineItem->item_type);
        $this->assertEquals('Special return instructions', $lineItem->note);
        $this->assertEquals(20_00, $lineItem->base_price_money_amount);
        $this->assertEquals(60_00, $lineItem->variation_total_price_money_amount);
        $this->assertEquals(60_00, $lineItem->gross_return_money_amount);
        $this->assertEquals(5_00, $lineItem->total_discount_money_amount);
        $this->assertEquals(2_00, $lineItem->total_service_charge_money_amount);
    }
}