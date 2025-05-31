<?php

namespace Nikolag\Square\Tests\Unit;

use Illuminate\Validation\ValidationException;
use Nikolag\Square\Models\ServiceCharge;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Utils\Constants;

class ServiceChargeValidationTest extends TestCase
{
    /**
     * Test that subtotal phase cannot be applied to products.
     *
     * @return void
     */
    public function test_subtotal_phase_cannot_be_applied_to_products(): void
    {
        $serviceCharge = factory(ServiceCharge::class)->make([
            'percentage' => 5.0,
            'calculation_phase' => Constants::SERVICE_CHARGE_CALCULATION_PHASE_SUBTOTAL,
            'treatment_type' => Constants::SERVICE_CHARGE_TREATMENT_LINE_ITEM,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Subtotal phase service charges cannot be applied at the product (line-item) level');

        $serviceCharge->validateProductLevelApplication();
    }

    /**
     * Test that total phase cannot be taxable.
     *
     * @return void
     */
    public function test_total_phase_cannot_be_taxable(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Total phase service charges cannot be taxable');

        factory(ServiceCharge::class)->create([
            'percentage' => 5.0,
            'calculation_phase' => Constants::SERVICE_CHARGE_CALCULATION_PHASE_TOTAL,
            'treatment_type' => Constants::SERVICE_CHARGE_TREATMENT_LINE_ITEM,
            'taxable' => true, // This should trigger validation error
        ]);
    }

    /**
     * Test that apportioned amount phase cannot use line item treatment.
     *
     * @return void
     */
    public function test_apportioned_amount_phase_cannot_use_line_item_treatment(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Apportioned amount phase cannot be used with line item treatment');

        factory(ServiceCharge::class)->create([
            'calculation_phase' => Constants::SERVICE_CHARGE_CALCULATION_PHASE_APPORTIONED_AMOUNT,
            'treatment_type' => Constants::SERVICE_CHARGE_TREATMENT_LINE_ITEM,
            'amount_money' => 500,
        ]);
    }
}
