<?php

namespace Nikolag\Square\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
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
     * Boot the model and set up event listeners.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($serviceCharge) {
            $serviceCharge->validateServiceChargeType();
        });

        static::updating(function ($serviceCharge) {
            $serviceCharge->validateServiceChargeType();
        });
    }

    /**
     * Validate that only one of percentage or amount_money is set.
     *
     * @return void
     * @throws ValidationException
     */
    protected function validateServiceChargeType()
    {
        $hasPercentage = !is_null($this->percentage) && $this->percentage !== 0;
        $hasAmount = !is_null($this->amount_money) && $this->amount_money !== 0;

        if ($hasPercentage && $hasAmount) {
            throw ValidationException::withMessages([
                'service_charge' => 'Service charge cannot have both percentage and amount_money set. Please specify only one.'
            ]);
        }

        if (!$hasPercentage && !$hasAmount) {
            throw ValidationException::withMessages([
                'service_charge' => 'Service charge must have either percentage or amount_money set.'
            ]);
        }
    }

    /**
     * Set the percentage attribute and clear amount_money.
     *
     * @param mixed $value
     * @return void
     */
    public function setPercentageAttribute($value)
    {
        $this->attributes['percentage'] = $value;

        if (!is_null($value) && $value !== 0) {
            $this->attributes['amount_money'] = null;
        }
    }

    /**
     * Set the amount_money attribute and clear percentage.
     *
     * @param mixed $value
     * @return void
     */
    public function setAmountMoneyAttribute($value)
    {
        $this->attributes['amount_money'] = $value;

        if (!is_null($value) && $value !== 0) {
            $this->attributes['percentage'] = null;
        }
    }

    /**
     * Check if this service charge is percentage-based.
     *
     * @return bool
     */
    public function isPercentage(): bool
    {
        return !is_null($this->percentage) && $this->percentage !== 0;
    }

    /**
     * Check if this service charge is fixed amount-based.
     *
     * @return bool
     */
    public function isFixedAmount(): bool
    {
        return !is_null($this->amount_money) && $this->amount_money !== 0;
    }

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
     * Returns a list of taxes that are applicable to this service charge.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function taxes()
    {
        return $this->morphToMany(Constants::TAX_NAMESPACE, 'featurable', 'nikolag_deductibles', 'featurable_id', 'deductible_id')->where('deductible_type', Constants::TAX_NAMESPACE)->withPivot('scope');
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
