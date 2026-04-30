<?php

namespace Nikolag\Square\Dto;

readonly class ServiceChargeEntry
{
    public function __construct(
        public mixed $serviceCharge,
        public int $amount,
    ) {}
}
