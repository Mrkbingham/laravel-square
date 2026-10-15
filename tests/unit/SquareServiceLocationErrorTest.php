<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Exception;
use Nikolag\Square\SquareConfig;
use Nikolag\Square\SquareService;
use PHPUnit\Framework\TestCase;
use Square\Apis\LocationsApi;
use Square\Http\ApiResponse;
use Square\Models\Builders\ErrorBuilder;

class SquareServiceLocationErrorTest extends TestCase
{
    /**
     * An error response carries an error array as its result, so the location reads
     * must check the response before unwrapping it rather than fail on the return type.
     */
    private function serviceAnsweringWithError(string $method): SquareService
    {
        $error = ErrorBuilder::init('AUTHENTICATION_ERROR', 'UNAUTHORIZED')
            ->detail('The access token has expired.')
            ->build();

        $response = $this->createStub(ApiResponse::class);
        $response->method('isSuccess')->willReturn(false);
        $response->method('isError')->willReturn(true);
        $response->method('getResult')->willReturn(['errors' => []]);
        $response->method('getErrors')->willReturn([$error]);
        $response->method('getStatusCode')->willReturn(401);

        $locationsApi = $this->createStub(LocationsApi::class);
        $locationsApi->method($method)->willReturn($response);

        $config = $this->createStub(SquareConfig::class);
        $config->method('locationsAPI')->willReturn($locationsApi);

        return new SquareService($config);
    }

    public function test_locations_throws_when_square_answers_with_an_error(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('AUTHENTICATION_ERROR: The access token has expired.');
        $this->expectExceptionCode(401);

        $this->serviceAnsweringWithError('listLocations')->locations();
    }

    public function test_sync_locations_throws_when_square_answers_with_an_error(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('AUTHENTICATION_ERROR: The access token has expired.');

        $this->serviceAnsweringWithError('listLocations')->syncLocations();
    }

    public function test_retrieve_location_throws_when_square_answers_with_an_error(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('AUTHENTICATION_ERROR: The access token has expired.');

        $this->serviceAnsweringWithError('retrieveLocation')->retrieveLocation('main');
    }
}
