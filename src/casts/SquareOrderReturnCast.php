<?php

namespace Nikolag\Square\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Square\Models\OrderReturn as SquareOrderReturn;

class SquareOrderReturnCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): SquareOrderReturn
    {
        if (is_null($value)) {
            return new SquareOrderReturn();
        }

        // Decode the JSON value into a SquareOrderReturn object
        $decodedValue = json_decode($value, true);
        return new SquareOrderReturn($decodedValue);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value);
    }
}
