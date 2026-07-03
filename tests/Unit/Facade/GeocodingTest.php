<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Facade;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Geocoding;

final class GeocodingTest extends TestCase
{
    #[Test]
    public function itDelegatesToGoogleGeocodingConnector(): void
    {
        $json = json_encode([
            'status' => 'OK',
            'results' => [[
                'formatted_address' => 'Tel Aviv, Israel',
                'geometry' => [
                    'location' => ['lat' => 32.08, 'lng' => 34.78],
                    'viewport' => [
                        'southwest' => ['lat' => 32.07, 'lng' => 34.77],
                        'northeast' => ['lat' => 32.09, 'lng' => 34.79],
                    ],
                ],
                'place_id' => 'abc123',
            ]],
        ]);

        $factory = new HttpFactory();
        $mockClient = new class ($json) implements ClientInterface {
            public function __construct(private readonly string $json) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], $this->json);
            }
        };

        // BYO the mock client through the public 3rd constructor arg. The
        // connector eagerly auto-discovers PSR-17 factories (guzzle is installed),
        // so on PHP 8.4 — where overwriting an initialized readonly property via
        // reflection is forbidden — we inject the factories only when not already
        // set (otherwise discovery is used). `$factory` is referenced to keep the
        // intent explicit.
        $geocoding = new Geocoding(LocationProviderId::Google, new GoogleConfig(apiKey: 'test-key'), $mockClient);

        $ref = new \ReflectionObject($geocoding);
        $connectorProp = $ref->getProperty('connector');
        $connector = $connectorProp->getValue($geocoding);
        self::assertIsObject($connector);
        $connectorRef = new \ReflectionObject($connector);
        $parentRef = $connectorRef->getParentClass();
        self::assertNotFalse($parentRef);
        $reqProp = $parentRef->getProperty('requestFactory');
        if (!$reqProp->isInitialized($connector)) {
            $reqProp->setValue($connector, $factory);
        }
        $streamProp = $parentRef->getProperty('streamFactory');
        if (!$streamProp->isInitialized($connector)) {
            $streamProp->setValue($connector, $factory);
        }

        $result = $geocoding->geocode(new GeocodeOptions(address: 'Tel Aviv'));

        self::assertCount(1, $result->candidates);
        self::assertSame('Tel Aviv, Israel', $result->candidates[0]->formattedAddress);
        self::assertSame(32.08, $result->candidates[0]->location->lat);
        self::assertSame(34.78, $result->candidates[0]->location->lng);
        self::assertSame('abc123', $result->candidates[0]->placeId);
        // viewport promoted to base shape on every candidate.
        self::assertNotNull($result->candidates[0]->viewport);
        self::assertSame(32.07, $result->candidates[0]->viewport->southwest->lat);
        self::assertSame(34.79, $result->candidates[0]->viewport->northeast->lng);
    }

    #[Test]
    public function itRejectsOsrmAsGeocodingProvider(): void
    {
        // OSRM is excluded from the geocoding union. PHP rejects the
        // pairing at construction time via the union type — OsrmConfig is not in
        // the constructor signature, so PHP throws a native \TypeError at the
        // parameter boundary before the constructor body runs (: construction
        // misuse is a programmer error, not a ConnectorError).
        $this->expectException(\TypeError::class);
        new Geocoding(LocationProviderId::Osrm, new \Thinwrap\Location\Config\OsrmConfig(baseUrl: 'https://example.com')); // @phpstan-ignore-line
    }

    #[Test]
    public function itExposesTheProviderIdForEverySupportedProvider(): void
    {
        $cases = [
            [LocationProviderId::Google, new GoogleConfig(apiKey: 'k'), 'google'],
            [LocationProviderId::Mapbox, new \Thinwrap\Location\Config\MapboxConfig(accessToken: 't'), 'mapbox'],
            [LocationProviderId::Here, new \Thinwrap\Location\Config\HereConfig(apiKey: 'k'), 'here'],
            [LocationProviderId::Esri, new \Thinwrap\Location\Config\EsriConfig(apiKey: 'k'), 'esri'],
            [LocationProviderId::TomTom, new \Thinwrap\Location\Config\TomTomConfig(apiKey: 'k'), 'tomtom'],
        ];

        foreach ($cases as [$providerId, $config, $expected]) {
            $geocoding = new Geocoding($providerId, $config);
            self::assertSame($expected, $geocoding->getProviderId());
        }
    }
}
