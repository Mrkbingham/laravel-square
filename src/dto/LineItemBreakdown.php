<?php

namespace Nikolag\Square\Dto;

readonly class LineItemBreakdown
{
    public function __construct(
        public int $baseCost,
        public int $discountAmount,
        public int $serviceChargeAmount,
        public int $taxAmount,
        public int $total,
    ) {
    }
}
