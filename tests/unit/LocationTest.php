<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Models\Address;
use Nikolag\Square\Models\Location;
use Nikolag\Square\Tests\TestCase;

class LocationTest extends TestCase
{
    /**
     * Test Location can be created with basic attributes.
     *
     * @return void
     */
    public function test_location_create(): void
    {
        $location = Location::create([
            'square_id'      => 'LOCATION_123',
            'name'           => 'Test Location',
            'status'         => 'ACTIVE',
            'country'        => 'US',
            'currency'       => 'USD',
            'phone_number'   => '+1 555-123-4567',
            'business_email' => 'test@example.com',
        ]);

        $this->assertNotNull($location->id);
        $this->assertEquals('LOCATION_123', $location->square_id);
        $this->assertEquals('Test Location', $location->name);
    }

    /**
     * Test Location has one Address via polymorphic address relationship.
     *
     * @return void
     */
    public function test_location_has_one_address_record(): void
    {
        $location = Location::create([
            'square_id' => 'LOCATION_ADDR_1',
            'name'      => 'Test Location',
            'status'    => 'ACTIVE',
            'country'   => 'US',
            'currency'  => 'USD',
        ]);

        $address = factory(Address::class)->make([
            'address_line_1'                  => '300 N State St',
            'locality'                        => 'Chicago',
            'administrative_district_level_1' => 'IL',
            'postal_code'                     => '60654',
            'country'                         => 'US',
        ]);

        $location->address()->save($address);

        $location->load('address');

        $this->assertInstanceOf(Address::class, $location->address);
        $this->assertEquals('300 N State St', $location->address->address_line_1);
        $this->assertEquals('Chicago', $location->address->locality);
        $this->assertEquals('IL', $location->address->administrative_district_level_1);
        $this->assertEquals('60654', $location->address->postal_code);
    }

    /**
     * Test Address belongs to Location via polymorphic addressable relationship.
     *
     * @return void
     */
    public function test_address_belongs_to_location(): void
    {
        $location = Location::create([
            'square_id' => 'LOCATION_ADDR_2',
            'name'      => 'Bakery HQ',
            'status'    => 'ACTIVE',
            'country'   => 'US',
            'currency'  => 'USD',
        ]);

        $address = factory(Address::class)->make([
            'address_line_1' => '721 N 14th St',
            'locality'       => 'Omaha',
            'postal_code'    => '68108',
        ]);

        $location->address()->save($address);
        $address->refresh();

        $this->assertEquals($location->id, $address->addressable_id);
        $this->assertEquals(Location::class, $address->addressable_type);
        $this->assertInstanceOf(Location::class, $address->addressable);
    }

    /**
     * Test Address record is persisted in the database with correct polymorphic type.
     *
     * @return void
     */
    public function test_location_address_record_persists_in_database(): void
    {
        $location = Location::create([
            'square_id' => 'LOCATION_ADDR_3',
            'name'      => 'Downtown Branch',
            'status'    => 'ACTIVE',
            'country'   => 'US',
            'currency'  => 'USD',
        ]);

        $location->address()->create([
            'address_line_1'                  => '1170 Ludlow Street',
            'locality'                        => 'Philadelphia',
            'administrative_district_level_1' => 'PA',
            'postal_code'                     => '19107',
            'country'                         => 'US',
        ]);

        $this->assertDatabaseHas('nikolag_addresses', [
            'addressable_type' => Location::class,
            'addressable_id'   => $location->id,
            'address_line_1'   => '1170 Ludlow Street',
            'locality'         => 'Philadelphia',
        ]);
    }

    /**
     * Test Location without address record returns null.
     *
     * @return void
     */
    public function test_location_without_address_record_returns_null(): void
    {
        $location = Location::create([
            'square_id' => 'LOCATION_NO_ADDR',
            'name'      => 'No Address Location',
            'status'    => 'ACTIVE',
            'country'   => 'US',
            'currency'  => 'USD',
        ]);

        $this->assertNull($location->address);
    }

    /**
     * Test updateOrCreate on address relationship updates existing address.
     *
     * @return void
     */
    public function test_location_address_record_update_or_create(): void
    {
        $location = Location::create([
            'square_id' => 'LOCATION_UOC',
            'name'      => 'Update Test',
            'status'    => 'ACTIVE',
            'country'   => 'US',
            'currency'  => 'USD',
        ]);

        // First create
        $location->address()->updateOrCreate([], [
            'address_line_1' => '100 Old St',
            'locality'       => 'OldTown',
            'postal_code'    => '00000',
        ]);

        $this->assertCount(1, Address::where('addressable_type', Location::class)
            ->where('addressable_id', $location->id)
            ->get());

        // Now update via the same pattern (mirrors SquareService sync logic)
        $location->address()->updateOrCreate([], [
            'address_line_1' => '200 New Ave',
            'locality'       => 'NewCity',
            'postal_code'    => '99999',
        ]);

        // Should still be 1 record, not 2
        $addresses = Address::where('addressable_type', Location::class)
            ->where('addressable_id', $location->id)
            ->get();

        $this->assertCount(1, $addresses);
        $this->assertEquals('200 New Ave', $addresses->first()->address_line_1);
        $this->assertEquals('NewCity', $addresses->first()->locality);
        $this->assertEquals('99999', $addresses->first()->postal_code);
    }

    /**
     * Test that address relationship can be eager loaded.
     *
     * @return void
     */
    public function test_location_address_can_be_eager_loaded(): void
    {
        $location = Location::create([
            'square_id' => 'LOCATION_EAGER',
            'name'      => 'Eager Load Test',
            'status'    => 'ACTIVE',
            'country'   => 'US',
            'currency'  => 'USD',
        ]);

        $location->address()->create([
            'address_line_1' => '200 Record Ave',
            'locality'       => 'Record City',
            'postal_code'    => '99999',
        ]);

        $loaded = Location::with('address')->find($location->id);

        $this->assertTrue($loaded->relationLoaded('address'));
        $this->assertEquals('200 Record Ave', $loaded->address->address_line_1);
        $this->assertEquals('Record City', $loaded->address->locality);
    }
}
