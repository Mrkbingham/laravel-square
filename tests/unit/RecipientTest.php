<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Models\Address;
use Nikolag\Square\Models\DeliveryDetails;
use Nikolag\Square\Models\Fulfillment;
use Nikolag\Square\Models\Recipient;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Tests\TestDataHolder;
use Square\Models\Address as SquareAddress;
use Square\Models\FulfillmentType;

class RecipientTest extends TestCase
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
     * Tests building the Square request Address object.
     *
     * @return void
     */
    public function test_get_square_request_address(): void
    {
        // Set up recipient with required relationships
        $deliveryDetails = factory(DeliveryDetails::class)->create();
        $fulfillment = factory(Fulfillment::class)->states(FulfillmentType::DELIVERY)->make();
        $fulfillment->fulfillmentDetails()->associate($deliveryDetails);
        $fulfillment->order()->associate($this->data->order);
        $fulfillment->save();

        /** @var Recipient $recipient */
        $recipient = factory(Recipient::class)->make();
        $recipient->fulfillment()->associate($fulfillment);
        $recipient->save();

        // Create an address for the recipient using the polymorphic relationship
        $address = factory(Address::class)->create([
            'address_line_1' => '123 Main St',
            'address_line_2' => 'Suite 100',
            'locality' => 'Chicago',
            'administrative_district_level_1' => 'IL',
            'postal_code' => '60601',
            'country' => 'US',
        ]);
        $recipient->address()->save($address);

        $squareAddress = $recipient->getSquareAddress();

        $this->assertNotNull($squareAddress, 'Square Address is null.');

        // Make sure it's the correct type
        $this->assertInstanceOf(SquareAddress::class, $squareAddress);

        // Make sure the values are correct
        $this->assertEquals('123 Main St', $squareAddress->getAddressLine1());
        $this->assertEquals('Suite 100', $squareAddress->getAddressLine2());
        $this->assertEquals('Chicago', $squareAddress->getLocality());
        $this->assertEquals('IL', $squareAddress->getAdministrativeDistrictLevel1());
        $this->assertEquals('60601', $squareAddress->getPostalCode());
        $this->assertEquals('US', $squareAddress->getCountry());
    }

    /**
     * Test recipient fulfillment relationship.
     *
     * @return void
     */
    public function test_recipient_fulfillment_relationship(): void
    {
        // Create fulfillment with delivery details
        $delivery = factory(DeliveryDetails::class)->create();
        $fulfillment = factory(Fulfillment::class)->states(FulfillmentType::DELIVERY)->make();
        $fulfillment->fulfillmentDetails()->associate($delivery);

        $order = factory(Order::class)->create();
        $fulfillment->order()->associate($order);
        $fulfillment->save();

        // Create a recipient and associate it with the fulfillment
        $recipient = factory(Recipient::class)->make();
        $recipient->fulfillment()->associate($fulfillment);
        $recipient->save();

        // Test the relationship
        $this->assertInstanceOf(Fulfillment::class, $recipient->fulfillment);
        $this->assertEquals($fulfillment->id, $recipient->fulfillment->id);
        $this->assertEquals($recipient->id, $fulfillment->recipient->id);
    }

    /**
     * Tests the recipient is deleted when the fulfillment is deleted.
     *
     * @return void
     */
    public function test_recipient_deleted_with_fulfillment(): void
    {
        // Create fulfillment with delivery details first
        $delivery = factory(DeliveryDetails::class)->create();
        $fulfillment = factory(Fulfillment::class)->states(FulfillmentType::DELIVERY)->make();
        $fulfillment->fulfillmentDetails()->associate($delivery);

        $order = factory(Order::class)->create();
        $fulfillment->order()->associate($order);
        $fulfillment->save();

        // Create a recipient and associate it with the fulfillment
        $recipient = factory(Recipient::class)->make();
        $recipient->fulfillment()->associate($fulfillment);
        $recipient->save();
        $this->assertInstanceOf(Recipient::class, $fulfillment->recipient);

        // Delete the fulfillment
        $fulfillment->delete();
        $this->assertNull($fulfillment->fresh());

        // Test the recipient is also deleted
        $this->assertNull($recipient->fresh());
    }
}
