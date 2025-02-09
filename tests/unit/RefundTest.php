<?php
/**
 * Created by PhpStorm.
 * User: nikola
 * Date: 6/20/18
 * Time: 02:33.
 */

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Exceptions\MissingPropertyException;
use Nikolag\Square\Facades\Square;
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
        $product = factory(Product::class)->create();
        $refund  = factory(Refund::class)->make([
            'refundable_id' => $product->id,
            'refundable_type' => Constants::PRODUCT_NAMESPACE,
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
        $product = factory(Product::class)->create();
        $refundData = [
            'refundable_id' => $product->id,
            'refundable_type' => Constants::PRODUCT_NAMESPACE,
            'quantity' => 1,
            'reason' => 'Test refund',
        ];
        factory(Refund::class)->create($refundData);

        $this->assertDatabaseHas('nikolag_order_refunds', $refundData);
    }
}
