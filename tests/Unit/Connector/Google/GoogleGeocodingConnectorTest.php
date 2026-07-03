<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Google;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Connector\Google\GoogleGeocodingConnector;
use Thinwrap\Location\DTO\Geocoding\AutocompleteOptions;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\DTO\Geocoding\ReverseGeocodeOptions;
use Thinwrap\Location\DTO\LatLng;

final class GoogleGeocodingConnectorTest extends TestCase
{
    #[Test]
    public function geocodeReturnsCorrectResult(): void
    {
        $json = json_encode([
            'status' => 'OK',
            'results' => [
                [
                    'formatted_address' => 'Tel Aviv',
                    'geometry' => [
                        'location' => ['lat' => 32.08, 'lng' => 34.78],
                    ],
                    'place_id' => 'abc',
                ],
            ],
        ]);

        $mockClient = new class ($json) implements ClientInterface {
            public function __construct(private readonly string $json) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], $this->json);
            }
        };

        $factory = new HttpFactory();
        $config = new GoogleConfig(apiKey: 'test-api-key');
        $connector = new GoogleGeocodingConnector($config, $mockClient, $factory, $factory);

        $result = $connector->geocode(new GeocodeOptions(address: 'Tel Aviv'));

        self::assertCount(1, $result->candidates);
        self::assertSame('Tel Aviv', $result->candidates[0]->formattedAddress);
        self::assertSame(32.08, $result->candidates[0]->location->lat);
        self::assertSame(34.78, $result->candidates[0]->location->lng);
        self::assertSame('abc', $result->candidates[0]->placeId);
    }

    #[Test]
    public function reverseGeocodeReturnsCorrectResult(): void
    {
        $json = json_encode([
            'status' => 'OK',
            'results' => [
                [
                    'formatted_address' => 'Tel Aviv',
                    'geometry' => [
                        'location' => ['lat' => 32.08, 'lng' => 34.78],
                    ],
                    'place_id' => 'abc',
                ],
            ],
        ]);

        $mockClient = new class ($json) implements ClientInterface {
            public function __construct(private readonly string $json) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], $this->json);
            }
        };

        $factory = new HttpFactory();
        $config = new GoogleConfig(apiKey: 'test-api-key');
        $connector = new GoogleGeocodingConnector($config, $mockClient, $factory, $factory);

        $result = $connector->reverseGeocode(new ReverseGeocodeOptions(
            location: new LatLng(32.08, 34.78),
        ));

        // reverse-geocode mirrors forward shape — `candidates[]` not single result.
        self::assertCount(1, $result->candidates);
        self::assertSame('Tel Aviv', $result->candidates[0]->formattedAddress);
        self::assertSame('abc', $result->candidates[0]->placeId);
    }

    #[Test]
    public function autocompleteReturnsCorrectResult(): void
    {
        // Places Autocomplete NEW API response shape:
        //   { suggestions: [{ placePrediction: { placeId, text: { text } } }] }
        $json = json_encode([
            'suggestions' => [
                [
                    'placePrediction' => [
                        'placeId' => 'abc',
                        'text' => ['text' => 'Tel Aviv, Israel'],
                    ],
                ],
            ],
        ]);

        $mockClient = new class ($json) implements ClientInterface {
            public function __construct(private readonly string $json) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], $this->json);
            }
        };

        $factory = new HttpFactory();
        $config = new GoogleConfig(apiKey: 'test-api-key');
        $connector = new GoogleGeocodingConnector($config, $mockClient, $factory, $factory);

        $result = $connector->autocomplete(new AutocompleteOptions(input: 'Tel Aviv'));

        self::assertCount(1, $result->predictions);
        self::assertSame('Tel Aviv, Israel', $result->predictions[0]->description);
        self::assertSame('abc', $result->predictions[0]->placeId);
    }

    #[Test]
    public function geocodeTranslatesCountryFilterToComponents(): void
    {
        // Capture the outgoing request to assert components=country:US|country:CA.
        $mockClient = new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'status' => 'OK',
                    'results' => [],
                ]));
            }
        };

        $factory = new HttpFactory();
        $config = new GoogleConfig(apiKey: 'test-api-key');
        $connector = new GoogleGeocodingConnector($config, $mockClient, $factory, $factory);

        $connector->geocode(new GeocodeOptions(
            address: 'Toronto',
            countryFilter: ['US', 'CA'],
        ));

        self::assertCount(1, $mockClient->captured);
        $uri = (string) $mockClient->captured[0]->getUri();
        self::assertStringContainsString('components=country%3AUS%7Ccountry%3ACA', $uri);
    }

    #[Test]
    public function autocompletePostsToPlacesNewApiWithHeaderAuth(): void
    {
        $mockClient = new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'suggestions' => [],
                ]));
            }
        };

        $factory = new HttpFactory();
        $config = new GoogleConfig(apiKey: 'header-key');
        $connector = new GoogleGeocodingConnector($config, $mockClient, $factory, $factory);

        $connector->autocomplete(new AutocompleteOptions(input: 'Tel'));

        self::assertCount(1, $mockClient->captured);
        $req = $mockClient->captured[0];
        self::assertSame('POST', $req->getMethod());
        self::assertStringContainsString('places.googleapis.com/v1/places:autocomplete', (string) $req->getUri());
        self::assertSame('header-key', $req->getHeaderLine('X-Goog-Api-Key'));
        // Body is JSON with input field.
        self::assertStringContainsString('"input":"Tel"', (string) $req->getBody());
    }
}
