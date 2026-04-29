<?php

namespace Nikolag\Square\Dto;

class OrderTotalsBreakdown
{
    public function __construct(
        public readonly int $netAmount,
        public readonly int $totalAmount,
        public readonly int $totalTaxAmount,
        public readonly int $totalDiscountAmount,
        public readonly int $totalServiceChargeAmount,
    ) {}
}
