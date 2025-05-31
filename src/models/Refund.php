<?php

namespace Nikolag\Square\Models;

use DateTimeInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Nikolag\Square\Utils\Constants;

class Refund extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'nikolag_order_refunds';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'refundable_id', 'refundable_type', 'quantity', 'reason', 'status',
    ];

    /**
     * Return a list of orders which use this tax.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function refundable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Check if the refund quantity exceeds the order product pivot quantity.
     *
     * @return void
     *
     * @throws Exception
     */
    public function checkRefundQuantity(): void
    {
        if ($this->refundable_type === Constants::ORDER_PRODUCT_NAMESPACE) {
            $orderProductPivot = $this->refundable;
            if ($this->quantity > $orderProductPivot->quantity) {
                throw new Exception('Refund quantity exceeds order product pivot quantity');
            }
        }
    }

    /**
     * Override the save method to include the quantity check.
     *
     * @param array $options
     * @return bool
     */
    public function save(array $options = []): bool
    {
        $this->checkRefundQuantity();
        return parent::save($options);
    }
}
