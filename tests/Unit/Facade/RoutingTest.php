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
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Config\OsrmConfig;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Routing;

final class RoutingTest extends TestCase
{
    #[Test]
    public function itDelegatesToGoogleConnector(): void
    {
        $json = json_encode([
            'routes' => [[
                'legs' => [['distanceMeters' => 5000, 'duration' => '300s', 'staticDuration' => '300s']],
                'distanceMeters' => 5000,
                'duration' => '300s',
                'staticDuration' => '300s',
                'polyline' => ['encodedPolyline' => 'abc123'],
            ]],
        ]);

        $factory = new HttpFactory();
        $mockClient = $this->createMockClient($json);

        // Pass the mock client through the public 3rd constructor arg (the BYO
        // seam); PHP 8.4 forbids overwriting the readonly `httpClient` via
        // reflection, so only the PSR-17 factories are reflection-injected.
        $routing = new Routing(
            LocationProviderId::Google,
            new GoogleConfig(apiKey: 'test-key'),
            $mockClient,
        );

        $this->injectFactoriesOnly($routing, $factory);

        $result = $routing->route(new RoutingOptions(
            waypoints: [new LatLng(32.08, 34.78), new LatLng(32.11, 34.85)],
        ));

        self::assertSame(5000.0, $result->totalDistanceMeters);
        self::assertSame(300.0, $result->totalDurationSeconds);
        self::assertSame('abc123', $result->polyline);
        self::assertCount(1, $result->legs);
    }

    #[Test]
    public function itDelegatesToMapboxConnector(): void
    {
        // `geometry` left empty: the previous `'encoded_polyline'` placeholder is
        // not a valid polyline6 string and now (correctly) trips the P2
        // malformed-polyline guard. This facade test only asserts distance/
        // duration delegation, so an empty geometry is sufficient.
        $json = json_encode([
            'code' => 'Ok',
            'routes' => [[
                'geometry' => '',
                'legs' => [['distance' => 5000, 'duration' => 300]],
                'distance' => 5000,
                'duration' => 300,
            ]],
            'waypoints' => [['name' => 'a'], ['name' => 'b']],
        ]);

        $factory = new HttpFactory();
        $mockClient = $this->createMockClient($json);

        $routing = new Routing(
            LocationProviderId::Mapbox,
            new MapboxConfig(accessToken: 'test-token'),
            $mockClient,
        );

        $this->injectFactoriesOnly($routing, $factory);

        $result = $routing->route(new RoutingOptions(
            waypoints: [new LatLng(32.08, 34.78), new LatLng(32.11, 34.85)],
        ));

        self::assertSame(5000.0, $result->totalDistanceMeters);
        self::assertSame(300.0, $result->totalDurationSeconds);
    }

    #[Test]
    public function itDelegatesToOsrmConnector(): void
    {
        $json = json_encode([
            'code' => 'Ok',
            'routes' => [[
                'geometry' => 'osrm_polyline',
                'legs' => [['distance' => 8000, 'duration' => 600]],
                'distance' => 8000,
                'duration' => 600,
            ]],
            'waypoints' => [['waypoint_index' => 0], ['waypoint_index' => 1]],
        ]);

        $factory = new HttpFactory();
        $mockClient = $this->createMockClient($json);

        $routing = new Routing(
            LocationProviderId::Osrm,
            new OsrmConfig(baseUrl: 'https://router.project-osrm.org'),
            $mockClient,
        );

        $this->injectFactoriesOnly($routing, $factory);

        $result = $routing->route(new RoutingOptions(
            waypoints: [new LatLng(32.08, 34.78), new LatLng(32.11, 34.85)],
        ));

        self::assertSame(8000.0, $result->totalDistanceMeters);
        self::assertSame(600.0, $result->totalDurationSeconds);
        self::assertSame('osrm_polyline', $result->polyline);
    }

    private function createMockClient(string $json): ClientInterface
    {
        return new class ($json) implements ClientInterface {
            public function __construct(private readonly string $json) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], $this->json);
            }
        };
    }

    /**
     * Inject the PSR-17 factories via reflection ONLY when they are not already
     * initialized. The connector constructor eagerly auto-discovers PSR-17
     * factories (guzzle is installed), so on PHP 8.4 — where overwriting an
     * initialized readonly property via reflection is forbidden — this is a
     * no-op and the discovered guzzle factories are used. The BYO client is
     * supplied through the public constructor arg, which is the seam under test.
     */
    private function injectFactoriesOnly(Routing $routing, HttpFactory $factory): void
    {
        $ref = new \ReflectionObject($routing);
        $connector = $ref->getProperty('connector')->getValue($routing);
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
    }
}
