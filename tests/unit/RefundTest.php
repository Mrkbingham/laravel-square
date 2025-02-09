<?php
/**
 * Created by PhpStorm.
 * User: nikola
 * Date: 6/20/18
 * Time: 02:33.
 */

namespace Nikolag\Square\Tests\Unit;

use Exception;
use Nikolag\Square\Exceptions\MissingPropertyException;
use Nikolag\Square\Facades\Square;
use Nikolag\Square\Models\OrderProductPivot;
use Nikolag\Square\Models\OrderRefundPivot;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Models\Refund;
use Nikolag\Square\Models\Tax;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Utils\Constants;

class RefundTest extends TestCase
{
    /**
     * Refund creation.
     *
     * @return void
     */
    public function test_refund_make(): void
    {
        $orderProductPivot = factory(OrderProductPivot::class)->create();
        $refund  = factory(Refund::class)->make([
            'refundable_id' => $orderProductPivot->id,
            'refundable_type' => Constants::ORDER_PRODUCT_NAMESPACE,
            'quantity' => 1,
            'reason' => 'Test refund',
        ]);

        $this->assertNotNull($refund, 'Refund is null.');
    }

    /**
     * Refund persisting.
     *
     * @return void
     */
    public function test_refund_create(): void
    {
        $orderProductPivot = factory(OrderProductPivot::class)->create();
        $refundData = [
            'refundable_id' => $orderProductPivot->id,
            'refundable_type' => Constants::ORDER_PRODUCT_NAMESPACE,
            'quantity' => 1,
            'reason' => 'Test refund',
        ];
        factory(Refund::class)->create($refundData);

        $this->assertDatabaseHas('nikolag_order_refunds', $refundData);
    }

    /**
     * Check creating a refund with order product pivot (for itemized refunds).
     *
     * @return void
     */
    public function test_refund_create_with_order_product_pivot(): void
    {
        /** @var Order */
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->make([
            'price' => 110,
        ]);
        $order->products()->save($product, ['quantity' => 3]);

        $refund = factory(Refund::class)->create([
            'refundable_id' => $order->products()->first()->pivot->id,
            'refundable_type' => Constants::ORDER_PRODUCT_NAMESPACE,
            'quantity' => 1,
            'reason' => 'Test refund',
        ]);

        $this->assertInstanceOf(OrderProductPivot::class, $refund->refundable);
        $this->assertInstanceOf(Order::class, $refund->refundable->order);
    }

    /**
     * Check refund matches quantity of order product pivot.
     *
     * @return void
     */
    public function test_refund_quantity_matches_order_product_pivot(): void
    {
        /** @var Order */
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->make([
            'price' => 110,
        ]);
        $order->products()->save($product, ['quantity' => 3]);

        // Expect an exception to be thrown
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Refund quantity exceeds product quantity');

        factory(Refund::class)->create([
            'refundable_id' => $order->products()->first()->pivot->id,
            'refundable_type' => Constants::ORDER_PRODUCT_NAMESPACE,
            'quantity' => 4,
            'reason' => 'Test refund',
        ]);
    }
}
