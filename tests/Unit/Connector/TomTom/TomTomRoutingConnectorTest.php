<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\TomTom;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\TomTomConfig;
use Thinwrap\Location\Connector\TomTom\TomTomRoutingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;

final class TomTomRoutingConnectorTest extends TestCase
{
    #[Test]
    public function getProviderIdReturnsTomTom(): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(200, [], '{}')));

        self::assertSame('tomtom', $connector->getProviderId());
    }

    #[Test]
    public function routeReturnsNormalizedRoutingResult(): void
    {
        $connector = self::makeConnector(self::respondingClient(self::happyResponse()));

        $result = $connector->route(new RoutingOptions(
            waypoints: [
                new LatLng(40.7128, -74.006),
                new LatLng(40.73, -73.995),
                new LatLng(40.758, -73.9855),
            ],
        ));

        self::assertCount(2, $result->legs);
        self::assertSame(5000.0, $result->legs[0]->distanceMeters);
        self::assertSame(300.0, $result->legs[0]->durationSeconds);
        self::assertSame(3000.0, $result->legs[1]->distanceMeters);
        self::assertSame(180.0, $result->legs[1]->durationSeconds);
        self::assertSame(8000.0, $result->totalDistanceMeters);
        self::assertSame(480.0, $result->totalDurationSeconds);
        self::assertNotEmpty($result->polyline);
        self::assertNull($result->waypointOrder);
    }

    #[Test]
    public function routeBuildsColonSeparatedCoordinatesUrlWithKeyAndTravelMode(): void
    {
        $recorder = self::recordingClient(self::happyResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [
                new LatLng(40.7128, -74.006),
                new LatLng(40.73, -73.995),
                new LatLng(40.758, -73.9855),
            ],
        ));

        self::assertNotNull($recorder->captured);
        self::assertSame('GET', $recorder->captured->getMethod());
        $uri = (string) $recorder->captured->getUri();

        self::assertStringContainsString('api.tomtom.com/routing/1/calculateRoute', $uri);
        self::assertStringContainsString('40.7128,-74.006:40.73,-73.995:40.758,-73.9855', $uri);
        self::assertStringContainsString('/json', $uri);
        self::assertStringContainsString('key=test-api-key', $uri);
        self::assertStringContainsString('travelMode=car', $uri);
        self::assertStringContainsString('routeType=fastest', $uri);
    }

    #[Test]
    public function routeMapsWalkingTravelModeToPedestrian(): void
    {
        $recorder = self::recordingClient(self::happyResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            travelMode: TravelMode::Walking,
        ));

        self::assertNotNull($recorder->captured);
        self::assertStringContainsString('travelMode=pedestrian', (string) $recorder->captured->getUri());
    }

    #[Test]
    public function routeMapsCyclingTravelModeToBicycle(): void
    {
        $recorder = self::recordingClient(self::happyResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            travelMode: TravelMode::Cycling,
        ));

        self::assertNotNull($recorder->captured);
        self::assertStringContainsString('travelMode=bicycle', (string) $recorder->captured->getUri());
    }

    #[Test]
    public function routeCollapsesAvoidFlagsIntoCsvAvoidParam(): void
    {
        $recorder = self::recordingClient(self::happyResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            avoidTolls: true,
            avoidFerries: true,
            avoidHighways: true,
        ));

        self::assertNotNull($recorder->captured);
        $uri = (string) $recorder->captured->getUri();
        // `http_build_query` urlencodes `,` to `%2C`.
        self::assertStringContainsString('avoid=tollRoads%2Cferries%2Cmotorways', $uri);
    }

    #[Test]
    public function routeOmitsAvoidParamWhenNoFlagsSet(): void
    {
        $recorder = self::recordingClient(self::happyResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
        ));

        self::assertNotNull($recorder->captured);
        self::assertStringNotContainsString('avoid=', (string) $recorder->captured->getUri());
    }

    #[Test]
    public function routeForwardsDepartAtWhenDepartureTimeIsSet(): void
    {
        $recorder = self::recordingClient(self::happyResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            departureTime: new \DateTimeImmutable('2024-06-15T08:00:00+00:00'),
        ));

        self::assertNotNull($recorder->captured);
        // `format('c')` -> ISO 8601, urlencoded into the query.
        $uri = (string) $recorder->captured->getUri();
        self::assertStringContainsString('departAt=', $uri);
        self::assertStringContainsString('2024-06-15T08', $uri);
    }

    #[Test]
    public function routeSendsComputeBestOrderWhenOptimizeIsTrueWithThreeWaypoints(): void
    {
        $recorder = self::recordingClient(self::happyResponse(optimized: true));
        $connector = self::makeConnector($recorder);

        $result = $connector->route(new RoutingOptions(
            waypoints: [
                new LatLng(40.7128, -74.006),
                new LatLng(40.73, -73.995),
                new LatLng(40.758, -73.9855),
            ],
            optimize: true,
        ));

        self::assertNotNull($recorder->captured);
        self::assertStringContainsString('computeBestOrder=true', (string) $recorder->captured->getUri());
        // optimizedWaypoints sorted by optimizedIndex -> providedIndex projection.
        self::assertSame([1, 0], $result->waypointOrder);
    }

    #[Test]
    public function routeEmitsCanonicalWaypointOrderFullVisitingSequence(): void
    {
        // Cross-language canonical waypointOrder parity fixture (PINNED).
        // Logical input [A,B,C,D]; optimal visiting order A,C,B,D ⇒ canonical
        // [0,2,1,3]. TomTom reports optimizedWaypoints {providedIndex,
        // optimizedIndex}; sorting by optimizedIndex and projecting providedIndex
        // yields [0,2,1,3]. Mirrors the TS sibling parity fixture exactly.
        $payload = [
            'routes' => [[
                'summary' => ['lengthInMeters' => 8000, 'travelTimeInSeconds' => 480],
                'legs' => [[
                    'summary' => ['lengthInMeters' => 8000, 'travelTimeInSeconds' => 480],
                    'points' => [
                        ['latitude' => 0, 'longitude' => 0],
                        ['latitude' => 3, 'longitude' => 3],
                    ],
                ]],
            ]],
            'optimizedWaypoints' => [
                ['providedIndex' => 0, 'optimizedIndex' => 0],
                ['providedIndex' => 2, 'optimizedIndex' => 1],
                ['providedIndex' => 1, 'optimizedIndex' => 2],
                ['providedIndex' => 3, 'optimizedIndex' => 3],
            ],
        ];

        $connector = self::makeConnector(self::respondingClient(
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload)),
        ));

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2), new LatLng(3, 3)],
            optimize: true,
        ));

        self::assertSame([0, 2, 1, 3], $result->waypointOrder);
    }

    #[Test]
    public function routeSkipsComputeBestOrderWhenOnlyTwoWaypointsEvenIfOptimizeTrue(): void
    {
        $recorder = self::recordingClient(self::happyResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            optimize: true,
        ));

        self::assertNotNull($recorder->captured);
        self::assertStringNotContainsString('computeBestOrder', (string) $recorder->captured->getUri());
    }

    #[Test]
    public function routeReencodesPointsToPrecision5Polyline(): void
    {
        $connector = self::makeConnector(self::respondingClient(self::happyResponse()));

        $result = $connector->route(new RoutingOptions(
            waypoints: [
                new LatLng(40.7128, -74.006),
                new LatLng(40.73, -73.995),
                new LatLng(40.758, -73.9855),
            ],
        ));

        self::assertNotEmpty($result->polyline);
        // Polyline alphabet is printable ASCII >= 63 (`?` and above).
        foreach (str_split($result->polyline) as $ch) {
            self::assertGreaterThanOrEqual(63, ord($ch));
        }
    }

    /**
     * @return iterable<string, array{int, ProviderCode}>
     */
    public static function mapVendorErrorCases(): iterable
    {
        yield 'http 401 -> AuthFailed' => [401, ProviderCode::AuthFailed];
        yield 'http 403 -> AuthFailed' => [403, ProviderCode::AuthFailed];
        yield 'http 429 -> RateLimited' => [429, ProviderCode::RateLimited];
        yield 'http 400 -> InvalidRequest' => [400, ProviderCode::InvalidRequest];
        yield 'http 404 -> InvalidRequest' => [404, ProviderCode::InvalidRequest];
        yield 'http 500 -> ProviderUnavailable' => [500, ProviderCode::ProviderUnavailable];
        yield 'http 503 -> ProviderUnavailable' => [503, ProviderCode::ProviderUnavailable];
        yield 'http 418 -> Unknown' => [418, ProviderCode::Unknown];
    }

    #[Test]
    #[DataProvider('mapVendorErrorCases')]
    public function routeMapsVendorErrorsToProviderCode(int $status, ProviderCode $expected): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(
            $status,
            ['Content-Type' => 'application/json'],
            (string) json_encode(['error' => ['description' => 'boom']]),
        )));

        try {
            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertSame($status, $error->statusCode);
            self::assertSame($expected, $error->providerCode);
        }
    }

    #[Test]
    public function routeSurfacesRetryAfterInProviderMessageAndCauseWithoutStructuredField(): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(
            429,
            ['Retry-After' => '30', 'Content-Type' => 'application/json'],
            (string) json_encode(['message' => 'Throttled']),
        )));

        try {
            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertSame(429, $error->statusCode);
            self::assertSame(ProviderCode::RateLimited, $error->providerCode);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString('retry after 30 seconds', $error->providerMessage);
            self::assertIsArray($error->cause);
            self::assertSame('30', $error->cause['retryAfter']);
            // No `retryAfterSeconds` field by design.
            self::assertFalse(property_exists($error, 'retryAfterSeconds'));
        }
    }

    #[Test]
    public function routeThrowsForEmptyRoutesEnvelope(): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(
            200,
            [],
            (string) json_encode(['routes' => []]),
        )));

        try {
            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertSame(ProviderCode::Unknown, $error->providerCode);
            self::assertSame('TomTom Routing returned no routes', $error->providerMessage);
        }
    }

    #[Test]
    public function routeThrowsWhenWaypointsBelowMinimum(): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(200, [], '{}')));

        try {
            $connector->route(new RoutingOptions(waypoints: [new LatLng(0, 0)]));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertNull($error->statusCode);
            self::assertSame(ProviderCode::InvalidRequest, $error->providerCode);
        }
    }

    #[Test]
    public function routeForwardsPassthroughQueryAndHeaders(): void
    {
        $recorder = self::recordingClient(self::happyResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            passthrough: new \Thinwrap\Location\DTO\Passthrough(
                headers: ['X-Custom' => 'yes'],
                query: ['trafficModel' => 'historical'],
            ),
        ));

        self::assertNotNull($recorder->captured);
        self::assertSame('yes', $recorder->captured->getHeaderLine('X-Custom'));
        self::assertStringContainsString('trafficModel=historical', (string) $recorder->captured->getUri());
    }

    private static function happyResponse(bool $optimized = false): ResponseInterface
    {
        $payload = [
            'routes' => [
                [
                    'summary' => ['lengthInMeters' => 8000, 'travelTimeInSeconds' => 480],
                    'legs' => [
                        [
                            'summary' => ['lengthInMeters' => 5000, 'travelTimeInSeconds' => 300],
                            'points' => [
                                ['latitude' => 40.7128, 'longitude' => -74.006],
                                ['latitude' => 40.73, 'longitude' => -73.995],
                            ],
                        ],
                        [
                            'summary' => ['lengthInMeters' => 3000, 'travelTimeInSeconds' => 180],
                            'points' => [
                                ['latitude' => 40.73, 'longitude' => -73.995],
                                ['latitude' => 40.758, 'longitude' => -73.9855],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        if ($optimized) {
            $payload['optimizedWaypoints'] = [
                ['providedIndex' => 1, 'optimizedIndex' => 0],
                ['providedIndex' => 0, 'optimizedIndex' => 1],
            ];
        }

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload));
    }

    private static function makeConnector(ClientInterface $client, ?TomTomConfig $config = null): TomTomRoutingConnector
    {
        $factory = new HttpFactory();

        return new TomTomRoutingConnector(
            $config ?? new TomTomConfig(apiKey: 'test-api-key'),
            $client,
            $factory,
            $factory,
        );
    }

    private static function respondingClient(ResponseInterface $response): ClientInterface
    {
        return new class ($response) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    /**
     * @return object{captured: ?RequestInterface}&ClientInterface
     */
    private static function recordingClient(ResponseInterface $response): ClientInterface
    {
        return new class ($response) implements ClientInterface {
            public ?RequestInterface $captured = null;

            public function __construct(private readonly ResponseInterface $response) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return $this->response;
            }
        };
    }
}
