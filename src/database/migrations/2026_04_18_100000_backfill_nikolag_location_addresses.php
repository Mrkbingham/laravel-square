<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Nikolag\Square\Models\Location;

return new class extends Migration
{
    /**
     * Backfill nikolag_addresses records from existing nikolag_locations JSON address data.
     */
    public function up(): void
    {
        $existingIds = DB::table('nikolag_addresses')
            ->where('addressable_type', Location::class)
            ->pluck('addressable_id')
            ->flip();

        $now = now();

        DB::table('nikolag_locations')
            ->whereNotNull('address')
            ->orderBy('id')
            ->chunk(100, function ($locations) use ($existingIds, $now) {
                $batch = [];

                foreach ($locations as $location) {
                    if ($existingIds->has($location->id)) {
                        continue;
                    }

                    $addressData = json_decode($location->address, true);

                    if (! is_array($addressData) || empty(array_filter($addressData))) {
                        continue;
                    }

                    $batch[] = [
                        'addressable_type'                => Location::class,
                        'addressable_id'                  => $location->id,
                        'address_line_1'                  => $addressData['address_line_1'] ?? null,
                        'address_line_2'                  => $addressData['address_line_2'] ?? null,
                        'address_line_3'                  => $addressData['address_line_3'] ?? null,
                        'locality'                        => $addressData['locality'] ?? null,
                        'administrative_district_level_1' => $addressData['administrative_district_level_1'] ?? null,
                        'administrative_district_level_2' => $addressData['administrative_district_level_2'] ?? null,
                        'administrative_district_level_3' => $addressData['administrative_district_level_3'] ?? null,
                        'sublocality'                     => $addressData['sublocality'] ?? null,
                        'sublocality_2'                   => $addressData['sublocality_2'] ?? null,
                        'sublocality_3'                   => $addressData['sublocality_3'] ?? null,
                        'postal_code'                     => $addressData['postal_code'] ?? null,
                        'country'                         => $addressData['country'] ?? null,
                        'created_at'                      => $now,
                        'updated_at'                      => $now,
                    ];
                }

                if (! empty($batch)) {
                    DB::table('nikolag_addresses')->insert($batch);
                }
            });
    }

    /**
     * Remove address records that were created for locations.
     */
    public function down(): void
    {
        DB::table('nikolag_addresses')
            ->where('addressable_type', Location::class)
            ->delete();
    }
};
