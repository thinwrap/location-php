<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Google;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Connector\Google\GoogleRoutingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;

/**
 * @phpstan-type RecordedRequest array{
 *     method: string,
 *     uri: string,
 *     headers: array<string, list<string>>,
 *     body: string
 * }
 */
final class GoogleRoutingConnectorTest extends TestCase
{
    #[Test]
    public function getProviderIdReturnsGoogle(): void
    {
        $factory = new HttpFactory();
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, [], '{}');
            }
        };
        $connector = new GoogleRoutingConnector(
            new GoogleConfig(apiKey: 'k'),
            $client,
            $factory,
            $factory,
        );

        self::assertSame('google', $connector->getProviderId());
    }

    #[Test]
    public function routeReturnsCorrectRoutingResult(): void
    {
        $json = (string) json_encode([
            'routes' => [
                [
                    'legs' => [
                        ['distanceMeters' => 5000, 'duration' => '300s', 'staticDuration' => '300s'],
                    ],
                    'distanceMeters' => 5000,
                    'duration' => '300s',
                    'staticDuration' => '300s',
                    'polyline' => ['encodedPolyline' => 'abc123'],
                ],
            ],
        ]);

        $client = self::respondingClient(new Response(200, ['Content-Type' => 'application/json'], $json));
        $factory = new HttpFactory();
        $connector = new GoogleRoutingConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(32.08, 34.78), new LatLng(32.10, 34.80)],
        ));

        self::assertCount(1, $result->legs);
        self::assertSame(5000.0, $result->legs[0]->distanceMeters);
        self::assertSame(300.0, $result->legs[0]->durationSeconds);
        self::assertSame(5000.0, $result->totalDistanceMeters);
        self::assertSame(300.0, $result->totalDurationSeconds);
        self::assertSame('abc123', $result->polyline);
        self::assertNull($result->waypointOrder);
        self::assertIsArray($result->raw);
    }

    #[Test]
    public function routeSetsExpectedWireAuthAndFieldMaskHeaders(): void
    {
        $recorder = self::recordingClient(new Response(200, [], (string) json_encode([
            'routes' => [[
                'legs' => [['distanceMeters' => 1, 'duration' => '1s']],
                'distanceMeters' => 1,
                'duration' => '1s',
                'polyline' => ['encodedPolyline' => 'p'],
            ]],
        ])));

        $factory = new HttpFactory();
        $connector = new GoogleRoutingConnector(new GoogleConfig(apiKey: 'secret-key'), $recorder, $factory, $factory);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(32.08, 34.78), new LatLng(32.10, 34.80)],
        ));

        $captured = $recorder->captured;
        self::assertNotNull($captured);
        self::assertSame('POST', $captured->getMethod());
        self::assertSame('https://routes.googleapis.com/directions/v2:computeRoutes', (string) $captured->getUri());
        self::assertSame('secret-key', $captured->getHeaderLine('X-Goog-Api-Key'));

        $fieldMask = $captured->getHeaderLine('X-Goog-FieldMask');
        self::assertStringContainsString('routes.legs.distanceMeters', $fieldMask);
        self::assertStringContainsString('routes.duration', $fieldMask);
        self::assertStringContainsString('routes.polyline.encodedPolyline', $fieldMask);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $captured->getBody(), true) ?? [];
        self::assertSame('DRIVE', $body['travelMode']);
        self::assertSame('TRAFFIC_UNAWARE', $body['routingPreference']);
        self::assertSame('ENCODED_POLYLINE', $body['polylineEncoding']);
    }

    #[Test]
    public function routeMapsTravelModesToGoogleEnumValues(): void
    {
        foreach ([
            [TravelMode::Walking, 'WALK'],
            [TravelMode::Cycling, 'BICYCLE'],
            [TravelMode::Driving, 'DRIVE'],
        ] as [$mode, $expected]) {
            $recorder = self::recordingClient(new Response(200, [], (string) json_encode([
                'routes' => [[
                    'legs' => [['distanceMeters' => 1, 'duration' => '1s']],
                    'distanceMeters' => 1, 'duration' => '1s', 'polyline' => ['encodedPolyline' => 'p'],
                ]],
            ])));

            $factory = new HttpFactory();
            $connector = new GoogleRoutingConnector(new GoogleConfig(apiKey: 'k'), $recorder, $factory, $factory);
            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
                travelMode: $mode,
            ));

            self::assertNotNull($recorder->captured);
            /** @var array<string, mixed> $body */
            $body = json_decode((string) $recorder->captured->getBody(), true) ?? [];
            self::assertSame($expected, $body['travelMode']);
        }
    }

    #[Test]
    public function routeSwitchesToTrafficAwareWhenDepartureTimeProvided(): void
    {
        $recorder = self::recordingClient(new Response(200, [], (string) json_encode([
            'routes' => [[
                'legs' => [['distanceMeters' => 1, 'duration' => '1s']],
                'distanceMeters' => 1, 'duration' => '1s', 'polyline' => ['encodedPolyline' => 'p'],
            ]],
        ])));

        $factory = new HttpFactory();
        $connector = new GoogleRoutingConnector(new GoogleConfig(apiKey: 'k'), $recorder, $factory, $factory);
        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            departureTime: new \DateTimeImmutable('2026-01-01T12:00:00+00:00'),
        ));

        self::assertNotNull($recorder->captured);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $recorder->captured->getBody(), true) ?? [];
        self::assertSame('TRAFFIC_AWARE', $body['routingPreference']);
        self::assertArrayHasKey('departureTime', $body);
    }

    #[Test]
    public function routeAttachesRouteModifiersAndOptimizeFlagForIntermediateWaypoints(): void
    {
        $recorder = self::recordingClient(new Response(200, [], (string) json_encode([
            'routes' => [[
                'legs' => [['distanceMeters' => 1, 'duration' => '1s']],
                'distanceMeters' => 1, 'duration' => '1s', 'polyline' => ['encodedPolyline' => 'p'],
                'optimizedIntermediateWaypointIndex' => [1, 0],
            ]],
        ])));

        $factory = new HttpFactory();
        $connector = new GoogleRoutingConnector(new GoogleConfig(apiKey: 'k'), $recorder, $factory, $factory);
        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(2, 2), new LatLng(3, 3), new LatLng(1, 1)],
            optimize: true,
            avoidTolls: true,
            avoidFerries: true,
            avoidHighways: false,
        ));

        self::assertNotNull($recorder->captured);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $recorder->captured->getBody(), true) ?? [];
        self::assertTrue($body['optimizeWaypointOrder']);
        self::assertSame(
            ['avoidTolls' => true, 'avoidFerries' => true, 'avoidHighways' => false],
            $body['routeModifiers'],
        );
        self::assertCount(2, $body['intermediates']);
        self::assertStringContainsString(
            'routes.optimizedIntermediateWaypointIndex',
            $recorder->captured->getHeaderLine('X-Goog-FieldMask'),
        );

        // Canonical waypointOrder = full visiting sequence of INPUT indices,
        // 0-based, origin/destination inclusive. Google reports the optimized
        // INTERMEDIATE order [1,0]; project to absolute input indices (+1) → [2,1],
        // prepend origin 0, append destination N-1=3 → [0,2,1,3]. (PINNED
        // cross-language contract; matches the TS sibling.)
        self::assertSame([0, 2, 1, 3], $result->waypointOrder);
    }

    #[Test]
    public function routeMergesPassthroughBodyAndHeaders(): void
    {
        $recorder = self::recordingClient(new Response(200, [], (string) json_encode([
            'routes' => [[
                'legs' => [['distanceMeters' => 1, 'duration' => '1s']],
                'distanceMeters' => 1, 'duration' => '1s', 'polyline' => ['encodedPolyline' => 'p'],
            ]],
        ])));

        $factory = new HttpFactory();
        $connector = new GoogleRoutingConnector(new GoogleConfig(apiKey: 'k'), $recorder, $factory, $factory);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            avoidTolls: true,
            passthrough: new Passthrough(
                body: [
                    'vehicleInfo' => ['emissionType' => 'GASOLINE'],
                    // Deep-merge into the connector-set routeModifiers.
                    'routeModifiers' => ['avoidIndoor' => true],
                ],
                headers: ['X-Goog-User-Project' => 'proj-123'],
            ),
        ));

        self::assertNotNull($recorder->captured);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $recorder->captured->getBody(), true) ?? [];
        self::assertSame(['emissionType' => 'GASOLINE'], $body['vehicleInfo']);
        // Connector-set routeModifiers kept and passthrough deep-merged in.
        self::assertSame(
            ['avoidTolls' => true, 'avoidFerries' => false, 'avoidHighways' => false, 'avoidIndoor' => true],
            $body['routeModifiers'],
        );
        self::assertSame('proj-123', $recorder->captured->getHeaderLine('X-Goog-User-Project'));
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>|null, ProviderCode}>
     */
    public static function mapVendorErrorCases(): iterable
    {
        yield '401 → AuthFailed' => [401, ['error' => ['message' => 'invalid key']], ProviderCode::AuthFailed];
        yield '403 PERMISSION_DENIED → AuthFailed' => [
            403,
            ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'no access']],
            ProviderCode::AuthFailed,
        ];
        yield '403 QUOTA_EXCEEDED → RateLimited' => [
            403,
            ['error' => ['status' => 'QUOTA_EXCEEDED', 'message' => 'over quota']],
            ProviderCode::RateLimited,
        ];
        yield '429 → RateLimited' => [429, ['error' => ['message' => 'slow down']], ProviderCode::RateLimited];
        yield '400 → InvalidRequest' => [400, ['error' => ['message' => 'bad arg']], ProviderCode::InvalidRequest];
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
        $connector = new GoogleRoutingConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

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
        $vendor = ['error' => ['message' => 'too many requests']];
        $client = self::respondingClient(new Response(
            429,
            ['Retry-After' => '30', 'Content-Type' => 'application/json'],
            (string) json_encode($vendor),
        ));
        $factory = new HttpFactory();
        $connector = new GoogleRoutingConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

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
            self::assertStringContainsString('too many requests', $error->providerMessage);

            self::assertIsArray($error->cause);
            self::assertSame('30', $error->cause['retryAfter']);
            self::assertArrayHasKey('error', $error->cause);
            // No `retryAfterSeconds` field by design.
            self::assertFalse(property_exists($error, 'retryAfterSeconds'));
        }
    }

    #[Test]
    public function routeThrowsForEmptyRoutesEnvelope(): void
    {
        $client = self::respondingClient(new Response(200, [], (string) json_encode(['routes' => []])));
        $factory = new HttpFactory();
        $connector = new GoogleRoutingConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

        $this->expectException(ConnectorError::class);
        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
        ));
    }

    /**
     * @return ClientInterface PSR-18 client returning a fixed response.
     */
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
