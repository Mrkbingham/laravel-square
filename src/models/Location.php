<?php

namespace Nikolag\Square\Models;

use DateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Square\Models\Location as SquareLocation;

class Location extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'nikolag_locations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'timezone',
        'capabilities',
        'status',
        'square_created_at',
        'merchant_id',
        'country',
        'language_code',
        'currency',
        'phone_number',
        'business_name',
        'type',
        'website_url',
        'business_hours',
        'business_email',
        'twitter_username',
        'instagram_username',
        'facebook_url',
        'mcc',
        'square_id',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'capabilities'      => 'array',
        'coordinates'       => 'json',
        'square_created_at' => 'datetime',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    /**
     * Get the address for this location.
     */
    public function address(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable');
    }

    /**
     * Processes the location data during sync.
     *
     * @param array $locationData The json serialized location data from the REST API.
     *
     * @var array
     */
    public static function processLocationData(SquareLocation $location): array
    {
        $locationData = $location->jsonSerialize();

        // Remove the ID and set it as the square_id
        $locationData['square_id'] = $locationData['id'];
        unset($locationData['id']);

        // Store raw address data for syncing to the address relationship
        $locationData['_address_data'] = $location->getAddress()?->jsonSerialize();
        unset($locationData['address']);

        // Update columns that are stored as more complex objects
        $locationData['capabilities'] = json_encode($location->getCapabilities());
        $locationData['business_hours'] = json_encode($location->getBusinessHours()?->jsonSerialize());

        // Cast any dates from the model
        $emptyModel = new self();
        foreach ($emptyModel->casts as $key => $value) {
            if ($value == 'datetime' && isset($locationData[$key])) {
                $value = new DateTime($locationData[$key]);
                $locationData[$key] = $value->format('Y-m-d H:i:s');
            }
        }

        return $locationData;
    }
}
