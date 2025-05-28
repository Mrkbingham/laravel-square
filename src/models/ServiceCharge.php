<?php

namespace Nikolag\Square\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Nikolag\Square\Utils\Constants;

class ServiceCharge extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'nikolag_service_charges';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'amount_money',
        'amount_currency',
        'percentage',
        'calculation_phase',
        'taxable',
        'treatment_type',
        'reference_id',
        'square_catalog_object_id',
        'square_created_at',
        'square_updated_at'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'amount_money' => 'integer',
        'percentage' => 'float',
        'taxable' => 'boolean',
        'square_created_at' => 'datetime',
        'square_updated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Return a list of orders which use this service charge.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function orders()
    {
        return $this->morphToMany(config('nikolag.connections.square.order.namespace'), 'deductible', 'nikolag_deductibles', 'deductible_id', 'featurable_id');
    }

    /**
     * Return a list of products which use this service charge.
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
