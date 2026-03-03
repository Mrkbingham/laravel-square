<?php

namespace Nikolag\Square\Traits;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Nikolag\Square\Models\Invoice;

trait HasInvoice
{
    /**
     * Get the invoice associated with the order.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'order_id');
    }

    /**
     * Check if the order has an invoice.
     *
     * @return bool
     */
    public function hasInvoice(): bool
    {
        return $this->invoice()->exists();
    }
}
