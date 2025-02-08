<?php

namespace Nikolag\Square\Traits;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Nikolag\Square\Utils\Constants;

trait HasRefunds
{
    /**
     * Return a refund, unrelated to line items - these are general refunds.
     *
     * @return MorphTo
     */
    public function refunds(): MorphTo
    {
        return $this->morphTo(Constants::REFUND_NAMESPACE, 'refundable', 'nikolag_order_refunds', 'refundable_id', 'refund_id')->where('refundable_type', config('nikolag.connections.square.order.namespace'));
    }
}
