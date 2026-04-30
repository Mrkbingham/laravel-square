<?php

namespace Nikolag\Square\Dto;

readonly class OrderTotalsBreakdown
{
    public function __construct(
        public int $netAmount,
        public int $totalAmount,
        public int $totalTaxAmount,
        public int $totalDiscountAmount,
        public int $totalServiceChargeAmount,
    ) {
    }
}
