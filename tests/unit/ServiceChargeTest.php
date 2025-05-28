<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Models\OrderProductPivot;
use Nikolag\Square\Models\ServiceCharge;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Utils\Constants;

class ServiceChargeTest extends TestCase
{
    /**
     * Service charge creation.
     *
     * @return void
     */
    public function test_service_charge_make(): void
    {
        $serviceCharge = factory(ServiceCharge::class)->create();

        $this->assertNotNull($serviceCharge, 'Service charge is null.');
    }

    /**
     * Service charge persisting.
     *
     * @return void
     */
    public function test_service_charge_create(): void
    {
        $name = $this->faker->name;

        $serviceCharge = factory(ServiceCharge::class)->create([
            'name' => $name,
        ]);

        $this->assertDatabaseHas('nikolag_service_charges', [
            'name' => $name,
        ]);
    }
}
