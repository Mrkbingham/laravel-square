<?php

namespace Nikolag\Square\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nikolag\Square\Models\Customer;
use Nikolag\Square\Models\Fulfillment;
use Nikolag\Square\Traits\HasAddress;
use Square\Models\Address as SquareAddress;

class Recipient extends Model
{
    use HasAddress;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'nikolag_recipients';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'display_name',
        'email_address',
        'phone_number',
        'customer_id',
        'fulfillment_id',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'id',
    ];

    /**
     * Return the fulfillment associated with this recipient.
     *
     * @return BelongsTo
     */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(Fulfillment::class, 'fulfillment_id', 'id');
    }

    /**
     * Return the customer associated with this recipient.
     *
     * @return BelongsTo
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }
}
