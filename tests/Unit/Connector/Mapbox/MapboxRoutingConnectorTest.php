<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Mapbox;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Connector\Mapbox\MapboxRoutingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Util\Polyline;

/**
 * @phpstan-type RecordedRequest array{
 *     method: string,
 *     uri: string,
 *     headers: array<string, list<string>>,
 *     body: string
 * }
 */
final class MapboxRoutingConnectorTest extends TestCase
{
    #[Test]
    public function getProviderIdReturnsMapbox(): void
    {
        $factory = new HttpFactory();
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, [], '{}');
            }
        };
        $connector = new MapboxRoutingConnector(
            new MapboxConfig(accessToken: 't'),
            $client,
            $factory,
            $factory,
        );

        self::assertSame('mapbox', $connector->getProviderId());
    }

    #[Test]
    public function routeReturnsCorrectRoutingResult(): void
    {
        // Encode precision-6 "0,0" → "0.000010,0.000020" → "0.000050,0.000080".
        $geometry = self::encodePolyline6([
            new LatLng(0.0, 0.0),
            new LatLng(0.000010, 0.000020),
            new LatLng(0.000050, 0.000080),
        ]);

        $json = (string) json_encode([
            'code' => 'Ok',
            'routes' => [
                [
                    'geometry' => $geometry,
                    'legs' => [
                        ['distance' => 5000, 'duration' => 300],
                    ],
                    'distance' => 5000,
                    'duration' => 300,
                ],
            ],
            'waypoints' => [
                ['name' => 'a'],
                ['name' => 'b'],
            ],
        ]);

        $client = self::respondingClient(new Response(200, ['Content-Type' => 'application/json'], $json));
        $factory = new HttpFactory();
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 't'), $client, $factory, $factory);

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(32.08, 34.78), new LatLng(32.10, 34.80)],
        ));

        self::assertCount(1, $result->legs);
        self::assertSame(5000.0, $result->legs[0]->distanceMeters);
        self::assertSame(300.0, $result->legs[0]->durationSeconds);
        self::assertSame(5000.0, $result->totalDistanceMeters);
        self::assertSame(300.0, $result->totalDurationSeconds);
        self::assertNull($result->waypointOrder);
        self::assertIsArray($result->raw);
    }

    #[Test]
    public function routeDispatchesToDirectionsV5WithoutOptimizationFlags(): void
    {
        $recorder = self::recordingClient(self::okResponse());
        $factory = new HttpFactory();
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'secret-token'), $recorder, $factory, $factory);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(32.08, 34.78), new LatLng(32.10, 34.80)],
            optimize: false,
            optimizeFixedOrigin: false,
            optimizeFixedDestination: false,
            isRoundTrip: false,
        ));

        self::assertNotNull($recorder->captured);
        self::assertSame('GET', $recorder->captured->getMethod());
        $uri = (string) $recorder->captured->getUri();
        self::assertStringStartsWith('https://api.mapbox.com/directions/v5/mapbox/driving/', $uri);
        // Coords joined as `lng,lat;lng,lat` (Mapbox path-segment convention).
        // We accept either raw or percent-encoded sub-delims since PSR-7
        // implementations differ on whether they encode `,` and `;` in paths.
        $decoded = rawurldecode($uri);
        self::assertStringContainsString('/driving/34.78,32.08;34.8,32.1', $decoded);
        self::assertStringContainsString('access_token=secret-token', $uri);
        self::assertStringContainsString('geometries=polyline6', $uri);
        self::assertStringContainsString('overview=full', $uri);
        self::assertStringContainsString('steps=true', $uri);
        self::assertStringContainsString('annotations=', $uri);
    }

    #[Test]
    public function routeDispatchesToOptimizedTripsV1WhenOptimizeOrRoundTripSet(): void
    {
        // Any optimization flag triggers the GET /optimized-trips/v1 branch.
        $cases = [
            ['optimize' => true,  'optimizeFixedOrigin' => false, 'optimizeFixedDestination' => false, 'isRoundTrip' => false],
            ['optimize' => false, 'optimizeFixedOrigin' => false, 'optimizeFixedDestination' => false, 'isRoundTrip' => true],
        ];

        foreach ($cases as $case) {
            $recorder = self::recordingClient(self::okResponse(trips: true));
            $factory = new HttpFactory();
            $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $recorder, $factory, $factory);

            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
                optimize: $case['optimize'],
                optimizeFixedOrigin: $case['optimizeFixedOrigin'],
                optimizeFixedDestination: $case['optimizeFixedDestination'],
                isRoundTrip: $case['isRoundTrip'],
            ));

            self::assertNotNull($recorder->captured);
            self::assertSame('GET', $recorder->captured->getMethod());
            $uri = (string) $recorder->captured->getUri();
            self::assertStringStartsWith('https://api.mapbox.com/optimized-trips/v1/mapbox/', $uri);
            // Coordinates ride in the path (lng,lat;…), not a POST body.
            self::assertStringContainsString('/driving/0,0;1,1;2,2', rawurldecode($uri));
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
            self::assertSame($case['isRoundTrip'] ? 'true' : 'false', $q['roundtrip']);
        }
    }

    #[Test]
    public function routeMapsOptimizationFlagsToSourceAndDestination(): void
    {
        $factory = new HttpFactory();

        // fixed-origin only → pin source, free destination.
        $recorder = self::recordingClient(self::okResponse(trips: true));
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $recorder, $factory, $factory);
        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
            optimizeFixedOrigin: true,
            optimizeFixedDestination: false,
        ));
        self::assertNotNull($recorder->captured);
        parse_str((string) parse_url((string) $recorder->captured->getUri(), PHP_URL_QUERY), $q);
        self::assertSame('first', $q['source']);
        self::assertSame('any', $q['destination']);

        // plain optimize (no fixed endpoints): v1 rejects any/any + roundtrip=false,
        // so keep BOTH endpoints and reorder the middle (matches the siblings).
        $recorder = self::recordingClient(self::okResponse(trips: true));
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $recorder, $factory, $factory);
        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
            optimize: true,
            optimizeFixedOrigin: false,
            optimizeFixedDestination: false,
        ));
        self::assertNotNull($recorder->captured);
        parse_str((string) parse_url((string) $recorder->captured->getUri(), PHP_URL_QUERY), $q);
        self::assertSame('first', $q['source']);
        self::assertSame('last', $q['destination']);
        self::assertSame('false', $q['roundtrip']);

        // isRoundTrip=true → roundtrip=true, source=first (returns to first waypoint).
        $recorder = self::recordingClient(self::okResponse(trips: true));
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $recorder, $factory, $factory);
        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
            optimize: false,
            isRoundTrip: true,
        ));
        self::assertNotNull($recorder->captured);
        parse_str((string) parse_url((string) $recorder->captured->getUri(), PHP_URL_QUERY), $q);
        self::assertSame('true', $q['roundtrip']);
        self::assertSame('first', $q['source']);
    }

    #[Test]
    public function routeMapsTravelModesToMapboxProfile(): void
    {
        foreach ([
            [TravelMode::Walking, 'walking'],
            [TravelMode::Cycling, 'cycling'],
            [TravelMode::Driving, 'driving'],
        ] as [$mode, $expected]) {
            $recorder = self::recordingClient(self::okResponse());
            $factory = new HttpFactory();
            $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $recorder, $factory, $factory);
            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
                optimize: false,
                optimizeFixedOrigin: false,
                optimizeFixedDestination: false,
                travelMode: $mode,
            ));

            self::assertNotNull($recorder->captured);
            self::assertStringContainsString("/directions/v5/mapbox/{$expected}/", (string) $recorder->captured->getUri());
        }
    }

    #[Test]
    public function routeReEncodesPrecision6PolylineToPrecision5(): void
    {
        $coords = [
            new LatLng(0.0, 0.0),
            new LatLng(0.000010, 0.000020),
            new LatLng(0.000050, 0.000080),
        ];
        $geometry6 = self::encodePolyline6($coords);

        $json = (string) json_encode([
            'code' => 'Ok',
            'routes' => [[
                'geometry' => $geometry6,
                'legs' => [['distance' => 1, 'duration' => 1]],
                'distance' => 1,
                'duration' => 1,
            ]],
        ]);

        $client = self::respondingClient(new Response(200, [], $json));
        $factory = new HttpFactory();
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $client, $factory, $factory);

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            optimize: false,
            optimizeFixedOrigin: false,
            optimizeFixedDestination: false,
        ));

        // Polyline is now precision-5: re-encode the same coords via the canonical
        // encoder and assert byte-for-byte equality.
        self::assertSame(Polyline::encodePolyline($coords), $result->polyline);
    }

    #[Test]
    public function routeReturnsWaypointOrderFromOptimizedDispatch(): void
    {
        $json = (string) json_encode([
            'code' => 'Ok',
            'trips' => [[
                'geometry' => '',
                'legs' => [['distance' => 1, 'duration' => 1]],
                'distance' => 1,
                'duration' => 1,
            ]],
            'waypoints' => [
                ['waypoint_index' => 0, 'name' => 'a'],
                ['waypoint_index' => 2, 'name' => 'b'],
                ['waypoint_index' => 1, 'name' => 'c'],
            ],
        ]);

        $client = self::respondingClient(new Response(200, [], $json));
        $factory = new HttpFactory();
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $client, $factory, $factory);

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
            optimize: true,
            optimizeFixedOrigin: false,
            optimizeFixedDestination: false,
        ));

        self::assertSame([0, 2, 1], $result->waypointOrder);
    }

    #[Test]
    public function waypointOrderInversionDirectionIsLockedByNonInvolutionFixture(): void
    {
        // Discriminating NON-involution fixture. The fixture above uses a
        // self-inverse permutation ([0,2,1]), so reverting the inverter would
        // still pass. Vendor waypoint_index = [1,2,0] is a 3-cycle: its inverse
        // [2,0,1] differs from the raw [1,2,0]. The inverter places each input
        // index at its visit position (order[waypoint_index[i]] = i):
        //   order[1]=0, order[2]=1, order[0]=2 ⇒ canonical [2,0,1].
        $json = (string) json_encode([
            'code' => 'Ok',
            'trips' => [[
                'geometry' => '',
                'legs' => [['distance' => 1, 'duration' => 1]],
                'distance' => 1,
                'duration' => 1,
            ]],
            'waypoints' => [
                ['waypoint_index' => 1, 'name' => 'a'],
                ['waypoint_index' => 2, 'name' => 'b'],
                ['waypoint_index' => 0, 'name' => 'c'],
            ],
        ]);

        $client = self::respondingClient(new Response(200, [], $json));
        $factory = new HttpFactory();
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $client, $factory, $factory);

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
            optimize: true,
            optimizeFixedOrigin: false,
            optimizeFixedDestination: false,
        ));

        // Canonical inverse of [1,2,0] is [2,0,1] (NOT the raw [1,2,0]).
        self::assertSame([2, 0, 1], $result->waypointOrder);
    }

    #[Test]
    public function routeAppendsExcludeQueryWhenAvoidFlagsSet(): void
    {
        $recorder = self::recordingClient(self::okResponse());
        $factory = new HttpFactory();
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $recorder, $factory, $factory);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            optimize: false,
            optimizeFixedOrigin: false,
            optimizeFixedDestination: false,
            avoidTolls: true,
            avoidFerries: true,
            avoidHighways: true,
        ));

        self::assertNotNull($recorder->captured);
        $uri = (string) $recorder->captured->getUri();
        self::assertStringContainsString('exclude=toll%2Cferry%2Cmotorway', $uri);
    }

    #[Test]
    public function routeMergesPassthroughQueryAndHeaders(): void
    {
        $recorder = self::recordingClient(self::okResponse());
        $factory = new HttpFactory();
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $recorder, $factory, $factory);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            optimize: false,
            optimizeFixedOrigin: false,
            optimizeFixedDestination: false,
            passthrough: new Passthrough(
                query: ['voice_units' => 'metric'],
                headers: ['X-Custom' => 'val'],
            ),
        ));

        self::assertNotNull($recorder->captured);
        self::assertStringContainsString('voice_units=metric', (string) $recorder->captured->getUri());
        self::assertSame('val', $recorder->captured->getHeaderLine('X-Custom'));
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>|null, ProviderCode}>
     */
    public static function mapVendorErrorCases(): iterable
    {
        yield '401 → AuthFailed' => [401, ['message' => 'unauthorized'], ProviderCode::AuthFailed];
        yield '403 → AuthFailed' => [403, ['message' => 'forbidden'], ProviderCode::AuthFailed];
        yield '422 NoRoute → InvalidRequest' => [422, ['code' => 'NoRoute', 'message' => 'no route'], ProviderCode::InvalidRequest];
        yield '422 NoTrips → InvalidRequest' => [422, ['code' => 'NoTrips', 'message' => 'no trip'], ProviderCode::InvalidRequest];
        yield '422 ProcessingError → Unknown' => [422, ['code' => 'ProcessingError', 'message' => 'err'], ProviderCode::Unknown];
        yield '429 → RateLimited' => [429, ['message' => 'slow down'], ProviderCode::RateLimited];
        yield '500 → ProviderUnavailable' => [500, null, ProviderCode::ProviderUnavailable];
        yield '503 → ProviderUnavailable' => [503, null, ProviderCode::ProviderUnavailable];
        yield '418 → Unknown' => [418, null, ProviderCode::Unknown];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('mapVendorErrorCases')]
    public function routeMapsVendorErrorsToProviderCode(int $status, ?array $body, ProviderCode $expected): void
    {
        $client = self::respondingClient(new Response($status, [], $body === null ? '' : (string) json_encode($body)));
        $factory = new HttpFactory();
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $client, $factory, $factory);

        try {
            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
                optimize: false,
                optimizeFixedOrigin: false,
                optimizeFixedDestination: false,
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
        $vendor = ['message' => 'too many requests'];
        $client = self::respondingClient(new Response(
            429,
            ['Retry-After' => '30', 'Content-Type' => 'application/json'],
            (string) json_encode($vendor),
        ));
        $factory = new HttpFactory();
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $client, $factory, $factory);

        try {
            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
                optimize: false,
                optimizeFixedOrigin: false,
                optimizeFixedDestination: false,
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertSame(429, $error->statusCode);
            self::assertSame(ProviderCode::RateLimited, $error->providerCode);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString('retry after 30 seconds', $error->providerMessage);
            self::assertStringContainsString('too many requests', $error->providerMessage);

            self::assertIsArray($error->cause);
            self::assertSame('30', $error->cause['retryAfter']);
            // No `retryAfterSeconds` field by design.
            self::assertFalse(property_exists($error, 'retryAfterSeconds'));
        }
    }

    #[Test]
    public function routeThrowsWhenEnvelopeCodeIsNotOk(): void
    {
        $json = (string) json_encode(['code' => 'NoRoute', 'message' => 'No route found']);
        $client = self::respondingClient(new Response(200, [], $json));
        $factory = new HttpFactory();
        $connector = new MapboxRoutingConnector(new MapboxConfig(accessToken: 'k'), $client, $factory, $factory);

        try {
            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
                optimize: false,
                optimizeFixedOrigin: false,
                optimizeFixedDestination: false,
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertSame(ProviderCode::InvalidRequest, $error->providerCode);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString('NoRoute', $error->providerMessage);
        }
    }

    /**
     * Precision-6 encoder for fixtures only. Mirrors {@see Polyline::encodePolyline}
     * with a 1e6 divisor — kept in the test to avoid leaking precision-6 into the
     * public utility surface (locked it).
     *
     * @param list<LatLng> $coords
     */
    private static function encodePolyline6(array $coords): string
    {
        $output = '';
        $prevLat = 0;
        $prevLng = 0;

        foreach ($coords as $coord) {
            $lat = (int) round($coord->lat * 1e6);
            $lng = (int) round($coord->lng * 1e6);

            $output .= self::encodeSignedValue($lat - $prevLat);
            $output .= self::encodeSignedValue($lng - $prevLng);

            $prevLat = $lat;
            $prevLng = $lng;
        }

        return $output;
    }

    private static function encodeSignedValue(int $value): string
    {
        $v = $value < 0 ? ~($value << 1) : ($value << 1);
        $output = '';
        while ($v >= 0x20) {
            $output .= chr((0x20 | ($v & 0x1F)) + 63);
            $v >>= 5;
        }
        $output .= chr($v + 63);

        return $output;
    }

    private static function okResponse(bool $trips = false): ResponseInterface
    {
        $key = $trips ? 'trips' : 'routes';
        $body = (string) json_encode([
            'code' => 'Ok',
            $key => [[
                'geometry' => '',
                'legs' => [['distance' => 1, 'duration' => 1]],
                'distance' => 1,
                'duration' => 1,
            ]],
        ]);

        return new Response(200, ['Content-Type' => 'application/json'], $body);
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
     * @return object{captured: ?RequestInterface}&ClientInterface PSR-18 client that captures the outbound request.
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
