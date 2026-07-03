<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Mapbox;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Connector\Mapbox\MapboxGeocodingConnector;
use Thinwrap\Location\DTO\Geocoding\AutocompleteOptions;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\DTO\Geocoding\ReverseGeocodeOptions;
use Thinwrap\Location\DTO\LatLng;

final class MapboxGeocodingConnectorTest extends TestCase
{
    #[Test]
    public function geocodeReturnsCorrectResult(): void
    {
        // Geocoding v6 response shape:
        //   features[].properties.full_address, features[].geometry.coordinates [lng, lat],
        //   features[].properties.mapbox_id
        $json = json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => [
                        'full_address' => 'Tel Aviv, Israel',
                        'mapbox_id' => 'dXJuOm1ieHBsYzpUZWxBdml2',
                    ],
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [34.78, 32.08],
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
        $config = new MapboxConfig(accessToken: 'test-token');
        $connector = new MapboxGeocodingConnector($config, $mockClient, $factory, $factory);

        $result = $connector->geocode(new GeocodeOptions(address: 'Tel Aviv'));

        self::assertCount(1, $result->candidates);
        self::assertSame('Tel Aviv, Israel', $result->candidates[0]->formattedAddress);
        self::assertSame(32.08, $result->candidates[0]->location->lat);
        self::assertSame(34.78, $result->candidates[0]->location->lng);
        self::assertSame('dXJuOm1ieHBsYzpUZWxBdml2', $result->candidates[0]->placeId);
    }

    #[Test]
    public function reverseGeocodeReturnsCorrectResult(): void
    {
        $json = json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => [
                        'full_address' => 'Tel Aviv, Israel',
                        'mapbox_id' => 'dXJuOm1ieHBsYzpUZWxBdml2',
                    ],
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [34.78, 32.08],
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
        $config = new MapboxConfig(accessToken: 'test-token');
        $connector = new MapboxGeocodingConnector($config, $mockClient, $factory, $factory);

        $result = $connector->reverseGeocode(new ReverseGeocodeOptions(
            location: new LatLng(32.08, 34.78),
        ));

        // reverse-geocode mirrors forward shape — `candidates[]` not single result.
        self::assertCount(1, $result->candidates);
        self::assertSame('Tel Aviv, Israel', $result->candidates[0]->formattedAddress);
        self::assertSame('dXJuOm1ieHBsYzpUZWxBdml2', $result->candidates[0]->placeId);
    }

    #[Test]
    public function autocompleteReturnsCorrectResult(): void
    {
        // Searchbox /suggest response shape: { suggestions: [{ full_address, mapbox_id }] }
        $json = json_encode([
            'suggestions' => [
                [
                    'full_address' => 'Tel Aviv, Israel',
                    'mapbox_id' => 'dXJuOm1ieHBsYzpUZWxBdml2',
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
        $config = new MapboxConfig(accessToken: 'test-token');
        $connector = new MapboxGeocodingConnector($config, $mockClient, $factory, $factory);

        $result = $connector->autocomplete(new AutocompleteOptions(input: 'Tel Aviv'));

        self::assertCount(1, $result->predictions);
        self::assertSame('Tel Aviv, Israel', $result->predictions[0]->description);
        self::assertSame('dXJuOm1ieHBsYzpUZWxBdml2', $result->predictions[0]->placeId);
    }

    #[Test]
    public function autocompleteIncludesSessionTokenInQuery(): void
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
        $config = new MapboxConfig(accessToken: 'test-token');
        $connector = new MapboxGeocodingConnector($config, $mockClient, $factory, $factory);

        $connector->autocomplete(new AutocompleteOptions(input: 'Tel'));

        self::assertCount(1, $mockClient->captured);
        $query = $mockClient->captured[0]->getUri()->getQuery();
        // session_token is generated per-call; assert presence + non-empty value.
        self::assertMatchesRegularExpression('/(^|&)session_token=[A-Za-z0-9-]+/', $query);
        self::assertStringContainsString('searchbox/v1/suggest', (string) $mockClient->captured[0]->getUri());
    }

    #[Test]
    public function geocodeAppliesCountryFilterAsLowercaseCsv(): void
    {
        $mockClient = new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'type' => 'FeatureCollection',
                    'features' => [],
                ]));
            }
        };

        $factory = new HttpFactory();
        $config = new MapboxConfig(accessToken: 'test-token');
        $connector = new MapboxGeocodingConnector($config, $mockClient, $factory, $factory);

        $connector->geocode(new GeocodeOptions(
            address: 'Toronto',
            countryFilter: ['US', 'CA'],
        ));

        $query = $mockClient->captured[0]->getUri()->getQuery();
        self::assertStringContainsString('country=us%2Cca', $query);
    }
}
