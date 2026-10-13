<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Builders\SquareRequestBuilders\FulfillmentRequestBuilder;
use Nikolag\Square\Facades\Square;
use Nikolag\Square\Models\Fulfillment;
use Nikolag\Square\Models\Location;
use Nikolag\Square\Models\PickupDetails;
use Nikolag\Square\Models\Recipient;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Square\Models\FulfillmentType;

class FulfillmentRequestBuilderTest extends TestCase
{
    private const CHICAGO = 'America/Chicago';

    private FulfillmentRequestBuilder $builder;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->builder = new FulfillmentRequestBuilder();
    }

    /**
     * A stored pickup time is the location's wall-clock, so a summer date carries the CDT offset.
     *
     * @return void
     */
    public function test_pickup_at_is_anchored_in_the_location_timezone_during_daylight_time(): void
    {
        $fulfillment = $this->makeFulfillment(FulfillmentType::PICKUP, ['pickup_at' => '2026-07-15 07:00:00']);

        $details = $this->builder->buildPickupDetails($fulfillment, self::CHICAGO);

        $this->assertSame('2026-07-15T07:00:00-05:00', $details->getPickupAt());
    }

    /**
     * A winter date at the same location carries the CST offset.
     *
     * @return void
     */
    public function test_pickup_at_is_anchored_in_the_location_timezone_during_standard_time(): void
    {
        $fulfillment = $this->makeFulfillment(FulfillmentType::PICKUP, ['pickup_at' => '2026-01-15 07:00:00']);

        $details = $this->builder->buildPickupDetails($fulfillment, self::CHICAGO);

        $this->assertSame('2026-01-15T07:00:00-06:00', $details->getPickupAt());
    }

    /**
     * @return void
     */
    public function test_deliver_at_is_anchored_in_the_location_timezone(): void
    {
        $summer = $this->makeFulfillment(FulfillmentType::DELIVERY, ['deliver_at' => '2026-07-15 07:00:00']);
        $winter = $this->makeFulfillment(FulfillmentType::DELIVERY, ['deliver_at' => '2026-01-15 07:00:00']);

        $this->assertSame(
            '2026-07-15T07:00:00-05:00',
            $this->builder->buildDeliveryDetails($summer, self::CHICAGO)->getDeliverAt()
        );
        $this->assertSame(
            '2026-01-15T07:00:00-06:00',
            $this->builder->buildDeliveryDetails($winter, self::CHICAGO)->getDeliverAt()
        );
    }

    /**
     * @return void
     */
    public function test_expected_shipped_at_is_anchored_in_the_location_timezone(): void
    {
        $summer = $this->makeFulfillment(FulfillmentType::SHIPMENT, ['expected_shipped_at' => '2026-07-15 07:00:00']);
        $winter = $this->makeFulfillment(FulfillmentType::SHIPMENT, ['expected_shipped_at' => '2026-01-15 07:00:00']);

        $this->assertSame(
            '2026-07-15T07:00:00-05:00',
            $this->builder->buildShipmentDetails($summer, self::CHICAGO)->getExpectedShippedAt()
        );
        $this->assertSame(
            '2026-01-15T07:00:00-06:00',
            $this->builder->buildShipmentDetails($winter, self::CHICAGO)->getExpectedShippedAt()
        );
    }

    /**
     * Only the scheduled fulfillment time is a wall-clock; lifecycle timestamps stay true instants.
     *
     * @return void
     */
    public function test_other_fulfillment_timestamps_are_not_re_anchored(): void
    {
        $fulfillment = $this->makeFulfillment(FulfillmentType::PICKUP, [
            'pickup_at' => '2026-07-15 07:00:00',
            'placed_at' => '2026-07-14 12:00:00',
        ]);

        $details = $this->builder->buildPickupDetails($fulfillment, self::CHICAGO);

        $this->assertSame('2026-07-14T12:00:00+00:00', $details->getPlacedAt());
    }

    /**
     * @return void
     */
    public function test_a_missing_timezone_keeps_the_utc_output(): void
    {
        $fulfillment = $this->makeFulfillment(FulfillmentType::PICKUP, ['pickup_at' => '2026-07-15 07:00:00']);

        $this->assertSame('2026-07-15T07:00:00+00:00', $this->builder->buildPickupDetails($fulfillment)->getPickupAt());
        $this->assertSame(
            '2026-07-15T07:00:00+00:00',
            $this->builder->buildPickupDetails($fulfillment, null)->getPickupAt()
        );
    }

    /**
     * @return void
     */
    public function test_an_invalid_timezone_falls_back_to_utc(): void
    {
        $fulfillment = $this->makeFulfillment(FulfillmentType::PICKUP, ['pickup_at' => '2026-07-15 07:00:00']);

        $details = $this->builder->buildPickupDetails($fulfillment, 'Not/AZone');

        $this->assertSame('2026-07-15T07:00:00+00:00', $details->getPickupAt());
    }

    /**
     * The order request resolves the timezone from the location it is built for.
     *
     * @return void
     */
    public function test_order_request_anchors_fulfillment_times_in_the_order_location_timezone(): void
    {
        factory(Location::class)->create(['square_id' => 'L_CHICAGO', 'timezone' => self::CHICAGO]);
        $order = $this->createOrderWithPickupAt('2026-07-15 07:00:00');

        $request = Square::getSquareBuilder()->buildOrderRequest($order, 'L_CHICAGO', 'USD');

        $pickupAt = $request->getOrder()->getFulfillments()[0]->getPickupDetails()->getPickupAt();
        $this->assertSame('2026-07-15T07:00:00-05:00', $pickupAt);
    }

    /**
     * @return void
     */
    public function test_order_request_for_an_unknown_location_keeps_the_utc_output(): void
    {
        $order = $this->createOrderWithPickupAt('2026-07-15 07:00:00');

        $request = Square::getSquareBuilder()->buildOrderRequest($order, 'L_UNKNOWN', 'USD');

        $pickupAt = $request->getOrder()->getFulfillments()[0]->getPickupDetails()->getPickupAt();
        $this->assertSame('2026-07-15T07:00:00+00:00', $pickupAt);
    }

    /**
     * Make an unsaved fulfillment of the given type with overridden detail attributes.
     *
     * @param string $type
     * @param array  $detailAttributes
     *
     * @return Fulfillment
     */
    private function makeFulfillment(string $type, array $detailAttributes): Fulfillment
    {
        /** @var Fulfillment $fulfillment */
        $fulfillment = factory(Fulfillment::class)->states($type)->make();
        $fulfillment->fulfillmentDetails->forceFill($detailAttributes);
        $fulfillment->setRelation('recipient', factory(Recipient::class)->make());

        return $fulfillment;
    }

    /**
     * Persist an order carrying one pickup fulfillment scheduled at the given wall-clock.
     *
     * @param string $pickupAt
     *
     * @return Order
     */
    private function createOrderWithPickupAt(string $pickupAt): Order
    {
        $pickup = factory(PickupDetails::class)->create(['pickup_at' => $pickupAt]);

        $order = factory(Order::class)->create();
        $fulfillment = factory(Fulfillment::class)->states(FulfillmentType::PICKUP)->make();
        $fulfillment->fulfillmentDetails()->associate($pickup);
        $fulfillment->order()->associate($order);
        $fulfillment->save();

        $recipient = factory(Recipient::class)->make();
        $recipient->fulfillment()->associate($fulfillment);
        $recipient->save();

        return $order->fresh(['products', 'taxes', 'discounts', 'serviceCharges', 'fulfillments']);
    }
}
