<?php

namespace Nikolag\Square\Dto;

use Illuminate\Support\Collection;

readonly class OrderContext
{
    public function __construct(
        public Collection $allLineItems,
        public int $orderBaseCost,
        public Collection $orderScopedDiscounts,
        public Collection $orderScopedTaxes,
        public Collection $orderScopedServiceCharges,
        public array $serviceChargeApplicableBaseCosts,
    ) {}
}
