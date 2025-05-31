<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Models\OrderReturn;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Models\Tax;
use Nikolag\Square\Models\Discount;
use Nikolag\Square\Models\ServiceCharge;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Utils\Constants;
use Square\Models\Money;
use Square\Models\OrderMoneyAmounts;
use Square\Models\OrderReturn as SquareOrderReturn;
use Square\Models\OrderReturnLineItem;

class OrderReturnTest extends TestCase
{
    /**
     * Test OrderReturn creation and basic properties.
     *
     * @return void
     */
    public function test_order_return_creation(): void
    {
        $order = factory(Order::class)->create();

        /** @var OrderReturn */
        $orderReturn = factory(OrderReturn::class)->make([
            'source_order_id' => $order->id,
            'uid' => 'test-return-uid-123',
            'data' => $this->buildMockOrderReturn(),
        ]);

        $this->assertNotNull($orderReturn);
        $this->assertEquals($order->id, $orderReturn->source_order_id);
        $this->assertEquals('test-return-uid-123', $orderReturn->uid);
        $this->assertEquals(1500, $orderReturn->data->getReturnAmounts()->getTotalMoney()->getAmount());
        $this->assertEquals('USD', $orderReturn->data->getReturnAmounts()->getTotalMoney()->getCurrency());
    }

    /**
     * Builds a reusable mock OrderReturn model from square for testing.
     *
     * @return SquareOrderReturn
     */
    protected function buildMockOrderReturn(): SquareOrderReturn
    {
        $mockOrderReturn = new SquareOrderReturn();
        $mockOrderReturn->setUid('mock-return-uid');
        $mockOrderReturn->setSourceOrderId('mock-source-order-id');

        // Create a re-usable money object
        $money = new Money();
        $money->setCurrency('USD');

        // Build the order money amount that stores everything
        $returnAmounts = new OrderMoneyAmounts();

        // Total
        $totalMoney = $money;
        $totalMoney->setAmount(20_00); // $20.00 USD
        $returnAmounts->setTotalMoney($totalMoney); // $20.00 USD

        // Tax
        $taxMoney = $money;
        $taxMoney->setAmount(2_00); // $2.00 USD
        $returnAmounts->setTaxMoney($taxMoney); // $2.00 USD

        // Discount
        $discountMoney = $money;
        $discountMoney->setAmount(1_00); // $1.00 USD
        $returnAmounts->setDiscountMoney($discountMoney); // $1.00 USD

        // Tip
        $tipMoney = $money;
        $tipMoney->setAmount(1_50); // $1.50 USD
        $returnAmounts->setTipMoney($tipMoney); // $1.50 USD

        // Service Charge
        $serviceChargeMoney = $money;
        $serviceChargeMoney->setAmount(50); // $0.50 USD
        $returnAmounts->setServiceChargeMoney($serviceChargeMoney); // $0.50 USD

        // Set the return amounts on the mock order return
        $mockOrderReturn->setReturnAmounts($returnAmounts);

        // Set the line items
        $lineItem1Money = $lineItem2Money = $money;
        $lineItem1Money->setAmount(10_00); // $10.00 USD
        $lineItem2Money->setAmount(5_00); // $5.00 USD
        $lineItems = [
            [
                'uid' => 'line-item-1',
                'source_line_item_uid' => 'source-line-item-1',
                'name' => 'Test Item 1',
                'quantity' => 1,
                'total_money' => $lineItem1Money,
            ],
            [
                'uid' => 'line-item-2',
                'source_line_item_uid' => 'source-line-item-2',
                'name' => 'Test Item 2',
                'quantity' => 2,
                'total_money' => $lineItem2Money,
            ],
        ];

        $returnLineItems = [];
        foreach ($lineItems as $item) {
            $returnLineItem = new OrderReturnLineItem($item['quantity']);
            $returnLineItem->setUid($item['uid']);
            $returnLineItem->setSourceLineItemUid($item['source_line_item_uid']);
            $returnLineItem->setName($item['name']);
            $returnLineItem->setTotalMoney($item['total_money']);
            $returnLineItems[] = $returnLineItem;
        }
        $mockOrderReturn->setReturnLineItems($returnLineItems);

        // Skipping rounding adjustment for simplicity
        // $mockOrderReturn->setRoundingAdjustment(null);

        return $mockOrderReturn;
    }
}
