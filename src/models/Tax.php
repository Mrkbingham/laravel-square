<?php

namespace Nikolag\Square\Models;

use DateTimeInterface;
use Nikolag\Core\Models\Tax as CoreTax;
use Nikolag\Square\Utils\Constants;

class Tax extends CoreTax
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'type',
        'percentage',
        'reference_id',
        'square_catalog_object_id',
        'calculation_phase',
        'inclusion_type',
        'applies_to_custom_amounts',
        'enabled',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'percentage' => 'float',
        'applies_to_custom_amounts' => 'boolean',
        'enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'square_created_at' => 'datetime',
        'square_updated_at' => 'datetime',
    ];

    /**
     * Return a list of orders which use this tax.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function orders()
    {
        return $this->morphToMany(config('nikolag.connections.square.order.namespace'), 'deductible', 'nikolag_deductibles', 'deductible_id', 'featurable_id');
    }

    /**
     * Return a list of products which use this tax.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function products()
    {
        return $this->morphToMany(Constants::ORDER_PRODUCT_NAMESPACE, 'deductible', 'nikolag_deductibles', 'deductible_id', 'featurable_id');
    }

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}
