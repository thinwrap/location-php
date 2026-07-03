<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Esri;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\EsriConfig;
use Thinwrap\Location\Connector\Esri\EsriRoutingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\Enum\ProviderCode;

final class EsriRoutingConnectorTest extends TestCase
{
    #[Test]
    public function getProviderIdReturnsEsri(): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(200, [], '{}')));

        self::assertSame('esri', $connector->getProviderId());
    }

    #[Test]
    public function routeReturnsNormalizedRoutingResult(): void
    {
        $json = (string) json_encode([
            'routes' => [
                'features' => [
                    [
                        'attributes' => [
                            'Total_Length' => 5000.0,
                            'Total_Time'   => 5.0,
                        ],
                        'geometry' => [
                            'paths' => [
                                [[-120.2, 38.5], [-120.95, 40.7]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $connector = self::makeConnector(self::respondingClient(new Response(
            200,
            ['Content-Type' => 'application/json'],
            $json,
        )));

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(38.5, -120.2), new LatLng(40.7, -120.95)],
        ));

        self::assertCount(1, $result->legs);
        self::assertSame(5000.0, $result->totalDistanceMeters);
        // Total_Time minutes * 60 = seconds.
        self::assertSame(300.0, $result->totalDurationSeconds);
        self::assertSame(5000.0, $result->legs[0]->distanceMeters);
        self::assertSame(300.0, $result->legs[0]->durationSeconds);
        self::assertNotEmpty($result->polyline);
    }

    #[Test]
    public function routeEncodesStopsAsFeatureSetWithToken(): void
    {
        $recorder = self::recordingClient(new Response(200, [], (string) json_encode([
            'routes' => ['features' => [[
                'attributes' => ['Total_Length' => 1.0, 'Total_Time' => 1.0],
                'geometry' => ['paths' => [[[0, 0], [1, 1]]]],
            ]]],
        ])));

        $connector = self::makeConnector($recorder, new EsriConfig(apiKey: 'secret'));

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(32.08, 34.78), new LatLng(32.10, 34.80)],
        ));

        self::assertNotNull($recorder->captured);
        self::assertSame('POST', $recorder->captured->getMethod());
        self::assertStringStartsWith(
            'https://route-api.arcgis.com/arcgis/rest/services/World/Route/NAServer/Route_World/solve',
            (string) $recorder->captured->getUri(),
        );

        $form = [];
        parse_str((string) $recorder->captured->getBody(), $form);

        self::assertSame('secret', $form['token']);
        self::assertSame('json', $form['f']);
        self::assertIsString($form['stops']);

        /** @var array<string, mixed> $stops */
        $stops = json_decode($form['stops'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($stops);
        self::assertArrayHasKey('features', $stops);
        self::assertIsArray($stops['features']);
        self::assertCount(2, $stops['features']);

        $first = $stops['features'][0];
        self::assertIsArray($first);
        self::assertArrayHasKey('geometry', $first);
        $geom = $first['geometry'];
        self::assertIsArray($geom);
        // ESRI convention: x = lng, y = lat.
        self::assertSame(34.78, $geom['x']);
        self::assertSame(32.08, $geom['y']);
        self::assertSame(['wkid' => 4326], $geom['spatialReference']);
    }

    #[Test]
    public function routeForwardsArcgisTokenWhenApiKeyAbsent(): void
    {
        $recorder = self::recordingClient(new Response(200, [], (string) json_encode([
            'routes' => ['features' => [[
                'attributes' => ['Total_Length' => 1.0, 'Total_Time' => 1.0],
                'geometry' => ['paths' => [[[0, 0], [1, 1]]]],
            ]]],
        ])));

        $connector = self::makeConnector(
            $recorder,
            new EsriConfig(arcgisToken: 'refreshable-oauth-token'),
        );

        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
        ));

        self::assertNotNull($recorder->captured);
        $form = [];
        parse_str((string) $recorder->captured->getBody(), $form);
        self::assertSame('refreshable-oauth-token', $form['token']);
    }

    #[Test]
    public function routeRaisesConnectorErrorOn200WithErrorBody(): void
    {
        $body = [
            'error' => [
                'code'    => 498,
                'message' => 'Invalid token',
            ],
        ];

        $connector = self::makeConnector(self::respondingClient(new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode($body),
        )));

        try {
            $connector->route(new RoutingOptions(
                waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            // HTTP status was 200 even though Esri reported an error.
            self::assertSame(200, $error->statusCode);
            self::assertSame(ProviderCode::AuthFailed, $error->providerCode);
            self::assertSame('Invalid token', $error->providerMessage);
            self::assertIsArray($error->cause);
            self::assertSame(498, $error->cause['code']);
        }
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>|null, ProviderCode}>
     */
    public static function mapVendorErrorCases(): iterable
    {
        yield 'body 498 -> AuthFailed' => [200, ['error' => ['code' => 498, 'message' => 'token']], ProviderCode::AuthFailed];
        yield 'body 499 -> AuthFailed' => [200, ['error' => ['code' => 499, 'message' => 'token']], ProviderCode::AuthFailed];
        yield 'body 403 -> AuthFailed' => [200, ['error' => ['code' => 403, 'message' => 'forbidden']], ProviderCode::AuthFailed];
        yield 'body 400 -> InvalidRequest' => [200, ['error' => ['code' => 400, 'message' => 'bad']], ProviderCode::InvalidRequest];
        yield 'body 404 -> InvalidRequest' => [200, ['error' => ['code' => 404, 'message' => 'no route']], ProviderCode::InvalidRequest];
        yield 'body 500 -> ProviderUnavailable' => [200, ['error' => ['code' => 500, 'message' => 'oops']], ProviderCode::ProviderUnavailable];
        yield 'http 401 -> AuthFailed' => [401, null, ProviderCode::AuthFailed];
        yield 'http 429 -> RateLimited' => [429, null, ProviderCode::RateLimited];
        yield 'http 503 -> ProviderUnavailable' => [503, null, ProviderCode::ProviderUnavailable];
        yield 'http 400 -> InvalidRequest' => [400, null, ProviderCode::InvalidRequest];
        yield 'http 418 -> Unknown' => [418, null, ProviderCode::Unknown];
        // Esri 429-precedence: HTTP 429 wins over an ambiguous in-body code
        // that would otherwise fall through to Unknown.
        yield 'http 429 + body 12345 -> RateLimited' => [429, ['error' => ['code' => 12345, 'message' => 'Too Many Requests']], ProviderCode::RateLimited];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('mapVendorErrorCases')]
    public function routeMapsVendorErrorsToProviderCode(int $status, ?array $body, ProviderCode $expected): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(
            $status,
            [],
            $body === null ? '' : (string) json_encode($body),
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
            ['Retry-After' => '45', 'Content-Type' => 'application/json'],
            (string) json_encode(['error' => ['code' => 429, 'message' => 'Throttled']]),
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
            self::assertStringContainsString('retry after 45 seconds', $error->providerMessage);
            self::assertIsArray($error->cause);
            self::assertSame('45', $error->cause['retryAfter']);
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
            (string) json_encode(['routes' => ['features' => []]]),
        )));

        $this->expectException(ConnectorError::class);
        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1)],
        ));
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
    public function routeReconstructsLegsFromDirectionsFeatures(): void
    {
        // Three stops -> two legs. Each leg has two direction steps separated
        // by `esriDMTStop` features.
        $json = (string) json_encode([
            'routes' => ['features' => [[
                'attributes' => ['Total_Length' => 1000.0, 'Total_Time' => 10.0],
                'geometry' => ['paths' => [[[0, 0], [1, 1], [2, 2]]]],
            ]]],
            'directions' => [[
                'features' => [
                    // Origin stop.
                    ['attributes' => ['maneuverType' => 'esriDMTStop', 'length' => 0, 'time' => 0]],
                    // Leg 1 steps.
                    ['attributes' => ['maneuverType' => 'esriDMTStraight', 'length' => 200.0, 'time' => 2.0]],
                    ['attributes' => ['maneuverType' => 'esriDMTRightTurn', 'length' => 100.0, 'time' => 1.0]],
                    // Intermediate stop.
                    ['attributes' => ['maneuverType' => 'esriDMTStop', 'length' => 0, 'time' => 0]],
                    // Leg 2 steps.
                    ['attributes' => ['maneuverType' => 'esriDMTStraight', 'length' => 400.0, 'time' => 4.0]],
                    ['attributes' => ['maneuverType' => 'esriDMTLeftTurn', 'length' => 300.0, 'time' => 3.0]],
                    // Destination stop.
                    ['attributes' => ['maneuverType' => 'esriDMTStop', 'length' => 0, 'time' => 0]],
                ],
            ]],
        ]);

        $connector = self::makeConnector(self::respondingClient(new Response(200, [], $json)));

        $result = $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
        ));

        self::assertCount(2, $result->legs);
        self::assertSame(300.0, $result->legs[0]->distanceMeters);
        self::assertSame(180.0, $result->legs[0]->durationSeconds);
        self::assertSame(700.0, $result->legs[1]->distanceMeters);
        self::assertSame(420.0, $result->legs[1]->durationSeconds);
    }

    #[Test]
    public function routeAddsFindBestSequenceFormFieldsOnOptimize(): void
    {
        $recorder = self::recordingClient(new Response(200, [], (string) json_encode([
            'routes' => ['features' => [[
                'attributes' => ['Total_Length' => 1.0, 'Total_Time' => 1.0],
                'geometry' => ['paths' => [[[0, 0], [1, 1]]]],
            ]]],
        ])));

        $connector = self::makeConnector($recorder);

        // fixed flags now default to `false` (TS parity). `preserveFirstStop`/
        // `preserveLastStop` are only emitted when the consumer opts in, so set
        // them explicitly to assert they reach the form.
        $connector->route(new RoutingOptions(
            waypoints: [new LatLng(0, 0), new LatLng(1, 1), new LatLng(2, 2)],
            optimize: true,
            optimizeFixedOrigin: true,
            optimizeFixedDestination: true,
        ));

        self::assertNotNull($recorder->captured);
        $form = [];
        parse_str((string) $recorder->captured->getBody(), $form);
        self::assertSame('true', $form['findBestSequence']);
        self::assertSame('true', $form['preserveFirstStop']);
        self::assertSame('true', $form['preserveLastStop']);
    }

    #[Test]
    public function esriConfigRejectsMissingCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EsriConfig();
    }

    #[Test]
    public function esriConfigRejectsBothCredentialsTogether(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EsriConfig(apiKey: 'k', arcgisToken: 't');
    }

    private static function makeConnector(ClientInterface $client, ?EsriConfig $config = null): EsriRoutingConnector
    {
        $factory = new HttpFactory();

        return new EsriRoutingConnector(
            $config ?? new EsriConfig(apiKey: 'test-api-key'),
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
