<?php

namespace Nikolag\Square\Models;

use DateTimeInterface;
use Nikolag\Core\Models\Discount as CoreDiscount;
use Nikolag\Square\Traits\ReportsCanonicalMorphType;
use Nikolag\Square\Utils\Constants;
use Nikolag\Square\Utils\MorphAliases;

class Discount extends CoreDiscount
{
    use ReportsCanonicalMorphType;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'amount', 'percentage', 'reference_id', 'square_catalog_object_id',
    ];

    /**
     * Return a list of orders which use this discount.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function orders()
    {
        return $this->morphToMany(config('nikolag.connections.square.order.namespace'), 'deductible', 'nikolag_deductibles', 'deductible_id', 'featurable_id')->withPivotValue('featurable_type', MorphAliases::morphTypeOf(config('nikolag.connections.square.order.namespace')));
    }

    /**
     * Return a list of products which use this discount.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function products()
    {
        return $this->morphToMany(Constants::ORDER_PRODUCT_NAMESPACE, 'deductible', 'nikolag_deductibles', 'deductible_id', 'featurable_id')->withPivotValue('featurable_type', MorphAliases::morphTypeOf(Constants::ORDER_PRODUCT_NAMESPACE));
    }

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param \DateTimeInterface $date
     *
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}
