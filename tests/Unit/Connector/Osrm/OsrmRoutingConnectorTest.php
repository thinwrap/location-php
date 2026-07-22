<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Osrm;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\OsrmConfig;
use Thinwrap\Location\Connector\Osrm\OsrmRoutingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;

final class OsrmRoutingConnectorTest extends TestCase
{
    #[Test]
    public function getProviderIdReturnsOsrm(): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(200, [], '{}')));

        self::assertSame('osrm', $connector->getProviderId());
    }

    // ---------------------------------------------------------------------
    // constructor baseUrl validation.
    // ---------------------------------------------------------------------

    #[Test]
    public function constructorThrowsWhenBaseUrlIsEmpty(): void
    {
        $factory = new HttpFactory();

        try {
            new OsrmRoutingConnector(
                new OsrmConfig(baseUrl: ''),
                self::respondingClient(new Response(200, [], '{}')),
                $factory,
                $factory,
            );
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertNull($error->statusCode);
            self::assertSame(ProviderCode::InvalidRequest, $error->providerCode);
            self::assertSame('baseUrl is required for OSRM', $error->providerMessage);
        }
    }

    // ---------------------------------------------------------------------
    // pre-flight validation (raised before any HTTP work).
    // ---------------------------------------------------------------------

    #[Test]
    public function routeRejectsDepartureTimeAsUnsupportedField(): void
    {
        $connector = self::makeConnector(self::failingClient());

        try {
            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
                departureTime: new \DateTimeImmutable('2024-06-15T08:00:00+00:00'),
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertNull($error->statusCode);
            self::assertSame(ProviderCode::UnsupportedField, $error->providerCode);
            self::assertSame('OSRM does not support departureTime', $error->providerMessage);
        }
    }

    /**
     * @return iterable<string, array{string, callable: RoutingOptions}>
     */
    public static function avoidFlagCases(): iterable
    {
        yield 'avoidTolls' => [
            'avoidTolls',
            static fn(): RoutingOptions => new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
                avoidTolls: true,
            ),
        ];
        yield 'avoidFerries' => [
            'avoidFerries',
            static fn(): RoutingOptions => new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
                avoidFerries: true,
            ),
        ];
        yield 'avoidHighways' => [
            'avoidHighways',
            static fn(): RoutingOptions => new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
                avoidHighways: true,
            ),
        ];
    }

    #[Test]
    #[DataProvider('avoidFlagCases')]
    public function routeRejectsAvoidFlagsAsUnsupportedOption(string $flag, callable $build): void
    {
        $connector = self::makeConnector(self::failingClient());
        /** @var RoutingOptions $options */
        $options = $build();

        try {
            $connector->route($options);
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertNull($error->statusCode);
            self::assertSame(ProviderCode::UnsupportedOption, $error->providerCode);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString($flag, $error->providerMessage);
        }
    }

    #[Test]
    public function routeRemapsOpenOptimizeComboToFirstLast(): void
    {
        // `optimize=true` + neither endpoint fixed + `isRoundTrip=false` would be
        // OSRM /trip with source=any, destination=any, roundtrip=false — the combo
        // OSRM rejects with HTTP 400. It is remapped to source=first/destination=last
        // (open route, endpoints kept, middle reordered), matching the Mapbox v1 sibling.
        $recorder = self::recordingClient(self::happyTripResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
            optimize: true,
            optimizeFixedOrigin: false,
            optimizeFixedDestination: false,
            isRoundTrip: false,
        ));

        self::assertNotNull($recorder->captured);
        $uri = (string) $recorder->captured->getUri();
        self::assertStringContainsString('source=first', $uri);
        self::assertStringContainsString('destination=last', $uri);
        self::assertStringContainsString('roundtrip=false', $uri);
    }

    #[Test]
    public function routeRejectsLoneWaypointAsInvalidRequest(): void
    {
        $connector = self::makeConnector(self::failingClient());

        try {
            $connector->route(new RoutingOptions(waypoints: [new LatLng(0, 0)]));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertNull($error->statusCode);
            self::assertSame(ProviderCode::InvalidRequest, $error->providerCode);
        }
    }

    // ---------------------------------------------------------------------
    // /route/v1 dispatch.
    // ---------------------------------------------------------------------

    #[Test]
    public function routeDispatchesToRouteEndpointWithLngLatCoordsAndDefaultProfile(): void
    {
        $recorder = self::recordingClient(self::happyRouteResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(32.08, 34.78), new LatLng(32.10, 34.80)],
        ));

        self::assertNotNull($recorder->captured);
        self::assertSame('GET', $recorder->captured->getMethod());
        $uri = (string) $recorder->captured->getUri();

        self::assertStringContainsString('http://osrm.local/route/v1/driving/', $uri);
        // OSRM coords are `lng,lat;lng,lat`.
        self::assertStringContainsString('34.78,32.08;34.8,32.1', $uri);
        self::assertStringContainsString('overview=full', $uri);
        self::assertStringContainsString('geometries=polyline', $uri);
        self::assertStringContainsString('steps=true', $uri);
        self::assertStringContainsString('annotations=', $uri);
        self::assertStringContainsString('alternatives=false', $uri);
    }

    #[Test]
    public function routeMapsWalkingTravelModeToWalkingProfile(): void
    {
        $recorder = self::recordingClient(self::happyRouteResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            travelMode: TravelMode::Walking,
        ));

        self::assertNotNull($recorder->captured);
        self::assertStringContainsString('/route/v1/walking/', (string) $recorder->captured->getUri());
    }

    #[Test]
    public function routeMapsCyclingTravelModeToCyclingProfile(): void
    {
        $recorder = self::recordingClient(self::happyRouteResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            travelMode: TravelMode::Cycling,
        ));

        self::assertNotNull($recorder->captured);
        self::assertStringContainsString('/route/v1/cycling/', (string) $recorder->captured->getUri());
    }

    #[Test]
    public function routeNormalizesHappyResponseToRoutingResult(): void
    {
        $connector = self::makeConnector(self::respondingClient(self::happyRouteResponse()));

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(32.08, 34.78), new LatLng(32.10, 34.80)],
        ));

        self::assertCount(1, $result->legs);
        self::assertSame(5000.0, $result->legs[0]->distanceMeters);
        self::assertSame(300.0, $result->legs[0]->durationSeconds);
        self::assertSame(5000.0, $result->totalDistanceMeters);
        self::assertSame(300.0, $result->totalDurationSeconds);
        // Geometry forwarded as-is (precision-5 native).
        self::assertSame('abc123', $result->polyline);
        self::assertNull($result->waypointOrder);
        self::assertIsArray($result->raw);
    }

    // ---------------------------------------------------------------------
    // /trip/v1 dispatch.
    // ---------------------------------------------------------------------

    #[Test]
    public function routeDispatchesToTripEndpointWhenOptimizeIsTrue(): void
    {
        $recorder = self::recordingClient(self::happyTripResponse());
        $connector = self::makeConnector($recorder);

        // fixed flags now default to `false` (TS parity). `optimize: true`
        // alone would be the invalid OSRM combo (source=any, destination=any,
        // roundtrip=false) — so set the fixed flags explicitly to express the
        // "fixed endpoints" intent and get a valid `/trip` dispatch.
        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
            optimize: true,
            optimizeFixedOrigin: true,
            optimizeFixedDestination: true,
        ));

        self::assertNotNull($recorder->captured);
        $uri = (string) $recorder->captured->getUri();

        self::assertStringContainsString('/trip/v1/driving/', $uri);
        // optimizeFixedOrigin=true and optimizeFixedDestination=true
        // > source=first, destination=last.
        self::assertStringContainsString('source=first', $uri);
        self::assertStringContainsString('destination=last', $uri);
        self::assertStringContainsString('roundtrip=false', $uri);
        self::assertStringNotContainsString('alternatives=', $uri);
    }

    #[Test]
    public function routeMapsOptimizeFixedOriginFalseToSourceAny(): void
    {
        $recorder = self::recordingClient(self::happyTripResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
            optimize: true,
            optimizeFixedOrigin: false,
            optimizeFixedDestination: true,
        ));

        self::assertNotNull($recorder->captured);
        $uri = (string) $recorder->captured->getUri();
        self::assertStringContainsString('source=any', $uri);
        self::assertStringContainsString('destination=last', $uri);
    }

    #[Test]
    public function routeMapsIsRoundTripToRoundtripTrueAndDispatchesToTrip(): void
    {
        $recorder = self::recordingClient(self::happyTripResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
            isRoundTrip: true,
        ));

        self::assertNotNull($recorder->captured);
        $uri = (string) $recorder->captured->getUri();
        self::assertStringContainsString('/trip/v1/', $uri);
        self::assertStringContainsString('roundtrip=true', $uri);
    }

    #[Test]
    public function routeExtractsWaypointOrderFromTripEndpoint(): void
    {
        $connector = self::makeConnector(self::respondingClient(self::happyTripResponse()));

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2), new LatLng(3, 3)],
            optimize: true,
            // set fixed flags (default false) so `/trip` dispatch is a valid
            // OSRM combo (source=first, destination=last).
            optimizeFixedOrigin: true,
            optimizeFixedDestination: true,
        ));

        // Trip response carries 4 waypoints with vendor waypoint_index [0,2,1,3]
        // (visit-position per INPUT waypoint, in input order). Canonical
        // waypointOrder = full visiting sequence of INPUT indices: invert the
        // permutation → [0,2,1,3]. (PINNED cross-language contract; matches the
        // TS sibling + the Mapbox/Google/HERE/TomTom/Esri connectors.)
        self::assertSame([0, 2, 1, 3], $result->waypointOrder);
    }

    #[Test]
    public function waypointOrderInversionDirectionIsLockedByNonInvolutionFixture(): void
    {
        // Discriminating NON-involution fixture. The fixture above uses a
        // self-inverse permutation ([0,2,1,3]), so reverting the inverter would
        // still pass. Vendor waypoint_index = [1,2,0] is a 3-cycle: its inverse
        // [2,0,1] differs from the raw [1,2,0]. The inverter places each input
        // index at its visit position (order[waypoint_index[i]] = i):
        //   order[1]=0, order[2]=1, order[0]=2 ⇒ canonical [2,0,1].
        $payload = [
            'code' => 'Ok',
            'trips' => [[
                'geometry' => 'tripgeom',
                'distance' => 6000,
                'duration' => 360,
                'legs' => [
                    ['distance' => 3000, 'duration' => 180],
                    ['distance' => 3000, 'duration' => 180],
                ],
            ]],
            'waypoints' => [
                ['waypoint_index' => 1],
                ['waypoint_index' => 2],
                ['waypoint_index' => 0],
            ],
        ];
        $connector = self::makeConnector(self::respondingClient(
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload)),
        ));

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
            optimize: true,
            optimizeFixedOrigin: true,
            optimizeFixedDestination: true,
        ));

        // Canonical inverse of [1,2,0] is [2,0,1] (NOT the raw [1,2,0]).
        self::assertSame([2, 0, 1], $result->waypointOrder);
    }

    // ---------------------------------------------------------------------
    // in-body OSRM `code` mappings (200-OK envelope with code != Ok).
    // ---------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, ProviderCode}>
     */
    public static function inBodyCodeCases(): iterable
    {
        yield 'NoRoute -> InvalidRequest' => ['NoRoute', ProviderCode::InvalidRequest];
        yield 'NoSegment -> InvalidRequest' => ['NoSegment', ProviderCode::InvalidRequest];
        yield 'InvalidQuery -> InvalidRequest' => ['InvalidQuery', ProviderCode::InvalidRequest];
        yield 'InvalidOptions -> InvalidRequest' => ['InvalidOptions', ProviderCode::InvalidRequest];
        yield 'NoTrips -> InvalidRequest' => ['NoTrips', ProviderCode::InvalidRequest];
        yield 'TooBig -> InvalidRequest' => ['TooBig', ProviderCode::InvalidRequest];
        yield 'unknown-code -> Unknown' => ['SomeNovelCode', ProviderCode::Unknown];
    }

    #[Test]
    #[DataProvider('inBodyCodeCases')]
    public function routeMapsInBodyCodeToProviderCode(string $code, ProviderCode $expected): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode(['code' => $code, 'message' => 'boom']),
        )));

        try {
            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertSame(200, $error->statusCode);
            self::assertSame($expected, $error->providerCode);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString($code, $error->providerMessage);
        }
    }

    // ---------------------------------------------------------------------
    // HTTP-level error mappings (reverse-proxy injected statuses).
    // ---------------------------------------------------------------------

    /**
     * @return iterable<string, array{int, ProviderCode}>
     */
    public static function httpErrorCases(): iterable
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
    #[DataProvider('httpErrorCases')]
    public function routeMapsHttpStatusToProviderCode(int $status, ProviderCode $expected): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(
            $status,
            ['Content-Type' => 'application/json'],
            (string) json_encode(['message' => 'boom']),
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

    // ---------------------------------------------------------------------
    // no auth headers.
    // ---------------------------------------------------------------------

    #[Test]
    public function routeSendsNoAuthorizationOrApiKeyHeader(): void
    {
        $recorder = self::recordingClient(self::happyRouteResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
        ));

        self::assertNotNull($recorder->captured);
        self::assertSame('', $recorder->captured->getHeaderLine('Authorization'));
        $uri = (string) $recorder->captured->getUri();
        self::assertStringNotContainsString('access_token=', $uri);
        self::assertStringNotContainsString('apiKey=', $uri);
        self::assertStringNotContainsString('key=', $uri);
    }

    // ---------------------------------------------------------------------
    // Passthrough forwarding.
    // ---------------------------------------------------------------------

    #[Test]
    public function routeForwardsPassthroughQueryAndHeaders(): void
    {
        $recorder = self::recordingClient(self::happyRouteResponse());
        $connector = self::makeConnector($recorder);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            passthrough: new Passthrough(
                headers: ['X-Custom' => 'yes'],
                query: ['continue_straight' => 'true'],
            ),
        ));

        self::assertNotNull($recorder->captured);
        self::assertSame('yes', $recorder->captured->getHeaderLine('X-Custom'));
        $uri = (string) $recorder->captured->getUri();
        self::assertStringContainsString('continue_straight=true', $uri);
    }

    // ---------------------------------------------------------------------
    // Empty/malformed body handling.
    // ---------------------------------------------------------------------

    #[Test]
    public function routeThrowsForEmptyRoutesEnvelope(): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(
            200,
            [],
            (string) json_encode(['code' => 'Ok', 'routes' => []]),
        )));

        try {
            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertSame(ProviderCode::Unknown, $error->providerCode);
            self::assertSame('OSRM Routing returned no routes', $error->providerMessage);
        }
    }

    // ---------------------------------------------------------------------
    // Fixtures + helpers.
    // ---------------------------------------------------------------------

    private static function happyRouteResponse(): ResponseInterface
    {
        $payload = [
            'code'   => 'Ok',
            'routes' => [
                [
                    'geometry' => 'abc123',
                    'distance' => 5000,
                    'duration' => 300,
                    'legs'     => [
                        ['distance' => 5000, 'duration' => 300],
                    ],
                ],
            ],
        ];

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload));
    }

    private static function happyTripResponse(): ResponseInterface
    {
        $payload = [
            'code'      => 'Ok',
            'trips'     => [
                [
                    'geometry' => 'tripgeom',
                    'distance' => 9000,
                    'duration' => 540,
                    'legs'     => [
                        ['distance' => 3000, 'duration' => 180],
                        ['distance' => 3000, 'duration' => 180],
                        ['distance' => 3000, 'duration' => 180],
                    ],
                ],
            ],
            'waypoints' => [
                ['waypoint_index' => 0],
                ['waypoint_index' => 2],
                ['waypoint_index' => 1],
                ['waypoint_index' => 3],
            ],
        ];

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload));
    }

    private static function makeConnector(ClientInterface $client, ?OsrmConfig $config = null): OsrmRoutingConnector
    {
        $factory = new HttpFactory();

        return new OsrmRoutingConnector(
            $config ?? new OsrmConfig(baseUrl: 'http://osrm.local'),
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
     * Client that fails the test on any HTTP dispatch — used for pre-flight
     * validation cases that must throw BEFORE any HTTP work.
     */
    private static function failingClient(): ClientInterface
    {
        return new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                TestCase::fail('Connector should not dispatch HTTP for pre-flight validation failure');
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
