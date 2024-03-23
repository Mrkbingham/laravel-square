<?php

namespace Nikolag\Square\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PickupDetails extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'nikolag_pickup_details';

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
        'recipient_id',
        'expires_at',
        'scheduled_type',
        'pickup_at',
        'pickup_window_duration',
        'prep_time_duration',
        'note',
        'placed_at',
        'accepted_at',
        'rejected_at',
        'ready_at',
        'expired_at',
        'picked_up_at',
        'canceled_at',
        'cancel_reason',
        'is_curbside_pickup',
        'curbside_pickup_details',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'pickup_at' => 'datetime',
        'placed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'ready_at' => 'datetime',
        'expired_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'canceled_at' => 'datetime',
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
     * Fulfillment relationship
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function fulfillment()
    {
        return $this->morphOne(Fulfillment::class, 'fulfillmentDetails');
    }
}