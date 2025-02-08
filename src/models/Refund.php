<?php

namespace Nikolag\Square\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Nikolag\Square\Utils\Constants;

class Refund extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'refund_type', 'refund_id', 'quantity', 'reason', 'status',
    ];

    /**
     * Return a list of orders which use this tax.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne
     */
    public function order(): MorphOne
    {
        return $this->morphOne(config('nikolag.connections.square.order.namespace'), 'refundable');
    }

    /**
     * Return a list of products which use this tax.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne
     */
    public function product(): MorphOne
    {
        return $this->morphOne(Constants::ORDER_PRODUCT_NAMESPACE, 'refundable');
    }
}
