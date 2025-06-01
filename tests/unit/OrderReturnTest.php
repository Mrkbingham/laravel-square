<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Models\OrderReturn;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Tests\TestDataHolder;

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
        $this->assertEquals(1500, $orderReturn->data->getReturnAmounts()->getTotalMoney()->getAmount());
        $this->assertEquals('USD', $orderReturn->data->getReturnAmounts()->getTotalMoney()->getCurrency());
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
}
