<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Here;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\Connector\Here\HereRoutingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Providers\Here\DTO\HereRoutingOptions;
use Thinwrap\Location\Providers\Here\Enum\HereTransportMode;

final class HereRoutingConnectorTest extends TestCase
{
    /**
     * A valid HERE flex-polyline with precision 5, no third dimension,
     * encoding two points: (0, 0) and (0.00001, 0.00001).
     */
    private const FLEX_POLYLINE = 'KAAACC';

    #[Test]
    public function getProviderIdReturnsHere(): void
    {
        $factory = new HttpFactory();
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, [], '{}');
            }
        };
        $connector = new HereRoutingConnector(
            new HereConfig(apiKey: 'k'),
            $client,
            $factory,
            $factory,
        );

        self::assertSame('here', $connector->getProviderId());
    }

    #[Test]
    public function routeDirectReturnsCorrectRoutingResult(): void
    {
        $json = (string) json_encode([
            'routes' => [
                [
                    'sections' => [
                        [
                            'polyline' => self::FLEX_POLYLINE,
                            'summary' => ['length' => 5000, 'duration' => 300],
                        ],
                    ],
                ],
            ],
        ]);

        $client = self::respondingClient(new Response(200, ['Content-Type' => 'application/json'], $json));
        $factory = new HttpFactory();
        $connector = new HereRoutingConnector(new HereConfig(apiKey: 'k'), $client, $factory, $factory);

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(32.08, 34.78), new LatLng(32.10, 34.80)],
        ));

        self::assertCount(1, $result->legs);
        self::assertSame(5000.0, $result->legs[0]->distanceMeters);
        self::assertSame(300.0, $result->legs[0]->durationSeconds);
        self::assertSame(5000.0, $result->totalDistanceMeters);
        self::assertSame(300.0, $result->totalDurationSeconds);
        self::assertNotEmpty($result->polyline);
        self::assertNull($result->waypointOrder);
        self::assertIsArray($result->raw);
    }

    #[Test]
    public function routeBuildsExpectedWireQueryForDirectDispatch(): void
    {
        $recorder = self::recordingClient(new Response(200, [], (string) json_encode([
            'routes' => [[
                'sections' => [[
                    'polyline' => self::FLEX_POLYLINE,
                    'summary' => ['length' => 1, 'duration' => 1],
                ]],
            ]],
        ])));

        $factory = new HttpFactory();
        $connector = new HereRoutingConnector(new HereConfig(apiKey: 'secret'), $recorder, $factory, $factory);

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(32.08, 34.78), new LatLng(32.10, 34.80)],
        ));

        $captured = $recorder->captured;
        self::assertNotNull($captured);
        self::assertSame('GET', $captured->getMethod());
        self::assertStringStartsWith('https://router.hereapi.com/v8/routes', (string) $captured->getUri());

        $query = [];
        parse_str($captured->getUri()->getQuery(), $query);
        self::assertSame('secret', $query['apiKey']);
        self::assertSame('car', $query['transportMode']);
        self::assertSame('polyline,summary', $query['return']);
        self::assertSame('fast', $query['routingMode']);
        self::assertSame('32.08,34.78', $query['origin']);
        self::assertSame('32.1,34.8', $query['destination']);
    }

    #[Test]
    public function transportModeOverridesBaseTravelModeMapping(): void
    {
        $recorder = self::recordingClient(new Response(200, [], (string) json_encode([
            'routes' => [[
                'sections' => [[
                    'polyline' => self::FLEX_POLYLINE,
                    'summary' => ['length' => 1, 'duration' => 1],
                ]],
            ]],
        ])));

        $factory = new HttpFactory();
        $connector = new HereRoutingConnector(new HereConfig(apiKey: 'k'), $recorder, $factory, $factory);

        $connector->route(new HereRoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            transportMode: HereTransportMode::Truck,
        ));

        self::assertNotNull($recorder->captured);
        $query = [];
        parse_str($recorder->captured->getUri()->getQuery(), $query);
        self::assertSame('truck', $query['transportMode']);
    }

    #[Test]
    public function twoCallOptimizationUsesFindsequence2ThenRoutes(): void
    {
        // findsequence2 returns waypoints in a non-input order:
        //   start (idx 0) -> destination2 (input idx 2) -> destination1 (input idx 1) -> end (idx 3)
        $sequenceJson = (string) json_encode([
            'results' => [[
                'waypoints' => [
                    ['id' => 'start', 'sequence' => 0],
                    ['id' => 'destination2', 'sequence' => 1],
                    ['id' => 'destination1', 'sequence' => 2],
                    ['id' => 'end', 'sequence' => 3],
                ],
            ]],
        ]);

        $routesJson = (string) json_encode([
            'routes' => [[
                'sections' => [[
                    'polyline' => self::FLEX_POLYLINE,
                    'summary' => ['length' => 1000, 'duration' => 60],
                ]],
            ]],
        ]);

        $recorder = new class ($sequenceJson, $routesJson) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];
            private int $callIndex = 0;

            public function __construct(
                private readonly string $sequenceJson,
                private readonly string $routesJson,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $body = $this->callIndex === 0 ? $this->sequenceJson : $this->routesJson;
                $this->callIndex++;

                return new Response(200, ['Content-Type' => 'application/json'], $body);
            }
        };

        $factory = new HttpFactory();
        $connector = new HereRoutingConnector(new HereConfig(apiKey: 'k'), $recorder, $factory, $factory);

        $result = $connector->route(new RoutingOptions(
            waypoints: [
                new LatLng(0, 0),    // origin (idx 0)
                new LatLng(1, 1),    // intermediate (idx 1)
                new LatLng(2, 2),    // intermediate (idx 2)
                new LatLng(3, 3),    // destination (idx 3)
            ],
            optimize: true,
        ));

        // Two HTTP calls happened.
        self::assertCount(2, $recorder->captured);

        // First call: findsequence2.
        self::assertStringStartsWith(
            'https://wps.hereapi.com/v8/findsequence2',
            (string) $recorder->captured[0]->getUri(),
        );

        // Second call: /v8/routes, with the via order reflecting the sequence
        // (destination2 -> destination1 means input idx 2 then 1; we emit via
        // params in the reordered order, so via for waypoint at idx 2 then 1).
        $secondUri = (string) $recorder->captured[1]->getUri();
        self::assertStringStartsWith('https://router.hereapi.com/v8/routes', $secondUri);

        // The intermediates between origin and destination should appear in
        // re-ordered form: first idx 2 ('2,2'), then idx 1 ('1,1').
        $pos2 = strpos($secondUri, 'via=2%2C2');
        $pos1 = strpos($secondUri, 'via=1%2C1');
        self::assertNotFalse($pos2, 'Expected reordered via=2,2 in second-call URI');
        self::assertNotFalse($pos1, 'Expected reordered via=1,1 in second-call URI');
        self::assertLessThan($pos1, $pos2, 'Expected via for idx 2 to precede via for idx 1');

        // Canonical waypointOrder = full visiting sequence of INPUT indices,
        // 0-based, origin/destination inclusive. findsequence2 ordered the
        // waypoints start(0) -> destination2(input 2) -> destination1(input 1)
        // > end(3), so the canonical order is [0,2,1,3]. (PINNED cross-language
        // contract; matches the TS sibling parity fixture.)
        self::assertSame([0, 2, 1, 3], $result->waypointOrder);
    }

    #[Test]
    public function explicitOptimizeFixedOriginFiresFindsequence2(): void
    {
        // 4-flag trigger (TS-identical): an explicitly-set `optimizeFixedOrigin`
        // IS an explicit optimization request and DOES fire the two-call
        // findsequence2 workflow — even though `optimize` itself is false.
        $sequenceJson = (string) json_encode([
            'results' => [[
                'waypoints' => [
                    ['id' => 'start', 'sequence' => 0],
                    ['id' => 'destination2', 'sequence' => 1],
                    ['id' => 'destination1', 'sequence' => 2],
                    ['id' => 'end', 'sequence' => 3],
                ],
            ]],
        ]);
        $routesJson = (string) json_encode([
            'routes' => [[
                'sections' => [[
                    'polyline' => self::FLEX_POLYLINE,
                    'summary' => ['length' => 1000, 'duration' => 60],
                ]],
            ]],
        ]);

        $recorder = new class ($sequenceJson, $routesJson) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];
            private int $callIndex = 0;

            public function __construct(
                private readonly string $sequenceJson,
                private readonly string $routesJson,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $body = $this->callIndex === 0 ? $this->sequenceJson : $this->routesJson;
                $this->callIndex++;

                return new Response(200, ['Content-Type' => 'application/json'], $body);
            }
        };

        $factory = new HttpFactory();
        $connector = new HereRoutingConnector(new HereConfig(apiKey: 'k'), $recorder, $factory, $factory);

        $result = $connector->route(new RoutingOptions(
            waypoints: [
                new LatLng(0, 0),
                new LatLng(1, 1),
                new LatLng(2, 2),
                new LatLng(3, 3),
            ],
            optimizeFixedOrigin: true,
        ));

        self::assertCount(2, $recorder->captured);
        self::assertStringStartsWith(
            'https://wps.hereapi.com/v8/findsequence2',
            (string) $recorder->captured[0]->getUri(),
        );
        self::assertSame([0, 2, 1, 3], $result->waypointOrder);
    }

    #[Test]
    public function explicitOptimizeFixedDestinationFiresFindsequence2(): void
    {
        // 4-flag trigger (TS-identical): `optimizeFixedDestination` also fires.
        $sequenceJson = (string) json_encode([
            'results' => [[
                'waypoints' => [
                    ['id' => 'start', 'sequence' => 0],
                    ['id' => 'destination2', 'sequence' => 1],
                    ['id' => 'destination1', 'sequence' => 2],
                    ['id' => 'end', 'sequence' => 3],
                ],
            ]],
        ]);
        $routesJson = (string) json_encode([
            'routes' => [[
                'sections' => [[
                    'polyline' => self::FLEX_POLYLINE,
                    'summary' => ['length' => 1000, 'duration' => 60],
                ]],
            ]],
        ]);

        $recorder = new class ($sequenceJson, $routesJson) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];
            private int $callIndex = 0;

            public function __construct(
                private readonly string $sequenceJson,
                private readonly string $routesJson,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $body = $this->callIndex === 0 ? $this->sequenceJson : $this->routesJson;
                $this->callIndex++;

                return new Response(200, ['Content-Type' => 'application/json'], $body);
            }
        };

        $factory = new HttpFactory();
        $connector = new HereRoutingConnector(new HereConfig(apiKey: 'k'), $recorder, $factory, $factory);

        $result = $connector->route(new RoutingOptions(
            waypoints: [
                new LatLng(0, 0),
                new LatLng(1, 1),
                new LatLng(2, 2),
                new LatLng(3, 3),
            ],
            optimizeFixedDestination: true,
        ));

        self::assertCount(2, $recorder->captured);
        self::assertStringStartsWith(
            'https://wps.hereapi.com/v8/findsequence2',
            (string) $recorder->captured[0]->getUri(),
        );
        self::assertSame([0, 2, 1, 3], $result->waypointOrder);
    }

    #[Test]
    public function noOptimizationFlagsSkipsTheFindsequence2Call(): void
    {
        $recorder = new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'routes' => [[
                        'sections' => [[
                            'polyline' => HereRoutingConnectorTest::flex(),
                            'summary' => ['length' => 1, 'duration' => 1],
                        ]],
                    ]],
                ]));
            }
        };

        $factory = new HttpFactory();
        $connector = new HereRoutingConnector(new HereConfig(apiKey: 'k'), $recorder, $factory, $factory);

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
            // optimization is triggered only by `optimize` / `isRoundTrip`.
            // All four flags now default to false, so no findsequence2 call fires.
        ));

        self::assertCount(1, $recorder->captured);
        self::assertNull($result->waypointOrder);
    }

    #[Test]
    public function polylineRoundTripDecodesAndReencodesToPrecision5(): void
    {
        $json = (string) json_encode([
            'routes' => [[
                'sections' => [[
                    'polyline' => self::FLEX_POLYLINE,
                    'summary' => ['length' => 10, 'duration' => 5],
                ]],
            ]],
        ]);

        $client = self::respondingClient(new Response(200, [], $json));
        $factory = new HttpFactory();
        $connector = new HereRoutingConnector(new HereConfig(apiKey: 'k'), $client, $factory, $factory);

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(0.00001, 0.00001)],
        ));

        // Precision-5 encoding of (0,0) -> (0.00001, 0.00001) is "?CC?" or
        // similar Google-format. We don't pin the exact bytes — we just verify
        // the re-encode produces a non-empty Google-format polyline distinct
        // from the flex-polyline input (which has the precision header).
        self::assertNotEmpty($result->polyline);
        self::assertNotSame(self::FLEX_POLYLINE, $result->polyline);
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>|null, ProviderCode}>
     */
    public static function mapVendorErrorCases(): iterable
    {
        yield '401 -> AuthFailed' => [401, ['title' => 'Unauthorized'], ProviderCode::AuthFailed];
        yield '403 -> AuthFailed' => [403, ['title' => 'Forbidden'], ProviderCode::AuthFailed];
        yield '400 -> InvalidRequest' => [400, ['title' => 'Bad Request'], ProviderCode::InvalidRequest];
        yield '429 -> RateLimited' => [429, ['title' => 'Too many'], ProviderCode::RateLimited];
        yield '500 -> ProviderUnavailable' => [500, null, ProviderCode::ProviderUnavailable];
        yield '503 -> ProviderUnavailable' => [503, null, ProviderCode::ProviderUnavailable];
        yield '418 -> Unknown' => [418, null, ProviderCode::Unknown];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('mapVendorErrorCases')]
    public function routeMapsVendorErrorsToProviderCode(int $status, ?array $body, ProviderCode $expected): void
    {
        $client = self::respondingClient(new Response(
            $status,
            [],
            $body === null ? '' : (string) json_encode($body),
        ));
        $factory = new HttpFactory();
        $connector = new HereRoutingConnector(new HereConfig(apiKey: 'k'), $client, $factory, $factory);

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
        $vendor = ['title' => 'Too Many Requests', 'cause' => 'rate limit hit'];
        $client = self::respondingClient(new Response(
            429,
            ['Retry-After' => '30', 'Content-Type' => 'application/json'],
            (string) json_encode($vendor),
        ));
        $factory = new HttpFactory();
        $connector = new HereRoutingConnector(new HereConfig(apiKey: 'k'), $client, $factory, $factory);

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
            self::assertStringContainsString('Too Many Requests', $error->providerMessage);

            self::assertIsArray($error->cause);
            self::assertSame('30', $error->cause['retryAfter']);
            // No `retryAfterSeconds` field by design.
            self::assertFalse(property_exists($error, 'retryAfterSeconds'));
        }
    }

    #[Test]
    public function routeThrowsForEmptyRoutesEnvelope(): void
    {
        $client = self::respondingClient(new Response(200, [], (string) json_encode(['routes' => []])));
        $factory = new HttpFactory();
        $connector = new HereRoutingConnector(new HereConfig(apiKey: 'k'), $client, $factory, $factory);

        $this->expectException(ConnectorError::class);
        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
        ));
    }

    /**
     * Internal accessor used by the anonymous client in
     * {@see noOptimizationFlagsSkipsTheFindsequence2Call} — anonymous classes
     * cannot reference private constants on the enclosing class.
     */
    public static function flex(): string
    {
        return self::FLEX_POLYLINE;
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
