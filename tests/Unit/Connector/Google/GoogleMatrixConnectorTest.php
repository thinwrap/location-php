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
use Thinwrap\Location\Connector\Google\GoogleMatrixConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;

final class GoogleMatrixConnectorTest extends TestCase
{
    #[Test]
    public function getProviderIdReturnsGoogle(): void
    {
        $factory = new HttpFactory();
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, [], '');
            }
        };
        $connector = new GoogleMatrixConnector(
            new GoogleConfig(apiKey: 'k'),
            $client,
            $factory,
            $factory,
        );

        self::assertSame('google', $connector->getProviderId());
    }

    #[Test]
    public function matrixParsesNdjsonMultiLineResponseIntoCells(): void
    {
        $ndjson = implode("\n", [
            (string) json_encode([
                'originIndex' => 0,
                'destinationIndex' => 0,
                'distanceMeters' => 1000,
                'duration' => '60s',
                'staticDuration' => '60s',
                'condition' => 'ROUTE_EXISTS',
            ]),
            (string) json_encode([
                'originIndex' => 0,
                'destinationIndex' => 1,
                'distanceMeters' => 2000,
                'duration' => '120s',
                'staticDuration' => '120s',
                'condition' => 'ROUTE_EXISTS',
            ]),
            (string) json_encode([
                'originIndex' => 1,
                'destinationIndex' => 0,
                'distanceMeters' => 1500,
                'duration' => '90s',
                'staticDuration' => '90s',
                'condition' => 'ROUTE_EXISTS',
            ]),
            (string) json_encode([
                'originIndex' => 1,
                'destinationIndex' => 1,
                'distanceMeters' => 500,
                'duration' => '30s',
                'staticDuration' => '30s',
                'condition' => 'ROUTE_EXISTS',
            ]),
        ]);

        $client = self::respondingClient(new Response(200, ['Content-Type' => 'application/json'], $ndjson));
        $factory = new HttpFactory();
        $connector = new GoogleMatrixConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(40.7128, -74.006), new LatLng(40.758, -73.9855)],
            destinations: [new LatLng(40.7484, -73.9856), new LatLng(40.7614, -73.9776)],
        ));

        self::assertCount(4, $result->cells);
        self::assertSame(0, $result->cells[0]->originIndex);
        self::assertSame(0, $result->cells[0]->destinationIndex);
        self::assertSame(1000.0, $result->cells[0]->distanceMeters);
        self::assertSame(60.0, $result->cells[0]->durationSeconds);
        self::assertSame(1, $result->cells[3]->originIndex);
        self::assertSame(1, $result->cells[3]->destinationIndex);
        self::assertSame(500.0, $result->cells[3]->distanceMeters);
        self::assertSame(30.0, $result->cells[3]->durationSeconds);
    }

    #[Test]
    public function matrixOmitsFailedCellsFromCellsButRetainsThemInRaw(): void
    {
        $ndjson = implode("\n", [
            (string) json_encode([
                'originIndex' => 0,
                'destinationIndex' => 0,
                'distanceMeters' => 1000,
                'duration' => '60s',
            ]),
            (string) json_encode([
                'originIndex' => 0,
                'destinationIndex' => 1,
                'status' => ['code' => 3, 'message' => 'INVALID_ARGUMENT'],
            ]),
            (string) json_encode([
                'originIndex' => 1,
                'destinationIndex' => 0,
                'distanceMeters' => 1500,
                'duration' => '90s',
            ]),
        ]);

        $client = self::respondingClient(new Response(200, [], $ndjson));
        $factory = new HttpFactory();
        $connector = new GoogleMatrixConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0), new LatLng(1, 1)],
            destinations: [new LatLng(2, 2), new LatLng(3, 3)],
        ));

        self::assertCount(2, $result->cells);
        self::assertSame(0, $result->cells[0]->originIndex);
        self::assertSame(0, $result->cells[0]->destinationIndex);
        self::assertSame(1, $result->cells[1]->originIndex);
        self::assertSame(0, $result->cells[1]->destinationIndex);

        // Raw retains all three elements including the failed one.
        self::assertIsArray($result->raw);
        self::assertCount(3, $result->raw);
    }

    #[Test]
    public function matrixTolaratesArrayWrappedFixtureShape(): void
    {
        $json = (string) json_encode([
            [
                'originIndex' => 0,
                'destinationIndex' => 0,
                'distanceMeters' => 5000,
                'duration' => '300s',
                'staticDuration' => '300s',
                'condition' => 'ROUTE_EXISTS',
            ],
        ]);

        $client = self::respondingClient(new Response(200, ['Content-Type' => 'application/json'], $json));
        $factory = new HttpFactory();
        $connector = new GoogleMatrixConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(32.08, 34.78)],
            destinations: [new LatLng(32.10, 34.80)],
        ));

        self::assertCount(1, $result->cells);
        self::assertSame(5000.0, $result->cells[0]->distanceMeters);
        self::assertSame(300.0, $result->cells[0]->durationSeconds);
    }

    #[Test]
    public function matrixSetsExpectedWireAuthAndFieldMaskHeaders(): void
    {
        $recorder = self::recordingClient(new Response(200, [], ''));
        $factory = new HttpFactory();
        $connector = new GoogleMatrixConnector(new GoogleConfig(apiKey: 'secret-key'), $recorder, $factory, $factory);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
        ));

        $captured = $recorder->captured;
        self::assertNotNull($captured);
        self::assertSame('POST', $captured->getMethod());
        self::assertSame(
            'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix',
            (string) $captured->getUri(),
        );
        self::assertSame('secret-key', $captured->getHeaderLine('X-Goog-Api-Key'));

        $fieldMask = $captured->getHeaderLine('X-Goog-FieldMask');
        self::assertStringContainsString('originIndex', $fieldMask);
        self::assertStringContainsString('destinationIndex', $fieldMask);
        self::assertStringContainsString('distanceMeters', $fieldMask);
        self::assertStringContainsString('duration', $fieldMask);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $captured->getBody(), true) ?? [];
        self::assertSame('DRIVE', $body['travelMode']);
        self::assertSame('TRAFFIC_UNAWARE', $body['routingPreference']);
        self::assertIsArray($body['origins']);
        self::assertIsArray($body['destinations']);
        self::assertCount(1, $body['origins']);
        self::assertCount(1, $body['destinations']);
    }

    #[Test]
    public function matrixMapsTravelModesToGoogleEnumValues(): void
    {
        foreach ([
            [TravelMode::Walking, 'WALK'],
            [TravelMode::Cycling, 'BICYCLE'],
            [TravelMode::Driving, 'DRIVE'],
        ] as [$mode, $expected]) {
            $recorder = self::recordingClient(new Response(200, [], ''));
            $factory = new HttpFactory();
            $connector = new GoogleMatrixConnector(new GoogleConfig(apiKey: 'k'), $recorder, $factory, $factory);
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
                travelMode: $mode,
            ));

            self::assertNotNull($recorder->captured);
            /** @var array<string, mixed> $body */
            $body = json_decode((string) $recorder->captured->getBody(), true) ?? [];
            self::assertSame($expected, $body['travelMode']);
        }
    }

    #[Test]
    public function matrixSwitchesToTrafficAwareWhenDepartureTimeProvided(): void
    {
        $recorder = self::recordingClient(new Response(200, [], ''));
        $factory = new HttpFactory();
        $connector = new GoogleMatrixConnector(new GoogleConfig(apiKey: 'k'), $recorder, $factory, $factory);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
            departureTime: new \DateTimeImmutable('2026-01-01T12:00:00+00:00'),
        ));

        self::assertNotNull($recorder->captured);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $recorder->captured->getBody(), true) ?? [];
        self::assertSame('TRAFFIC_AWARE', $body['routingPreference']);
        self::assertArrayHasKey('departureTime', $body);
    }

    #[Test]
    public function matrixAttachesAvoidTollsRouteModifier(): void
    {
        $recorder = self::recordingClient(new Response(200, [], ''));
        $factory = new HttpFactory();
        $connector = new GoogleMatrixConnector(new GoogleConfig(apiKey: 'k'), $recorder, $factory, $factory);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
            avoidTolls: true,
        ));

        self::assertNotNull($recorder->captured);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $recorder->captured->getBody(), true) ?? [];
        self::assertSame(['avoidTolls' => true], $body['routeModifiers']);
    }

    #[Test]
    public function matrixMergesPassthroughBodyAndHeaders(): void
    {
        $recorder = self::recordingClient(new Response(200, [], ''));
        $factory = new HttpFactory();
        $connector = new GoogleMatrixConnector(new GoogleConfig(apiKey: 'k'), $recorder, $factory, $factory);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
            avoidTolls: true,
            passthrough: new Passthrough(
                body: [
                    'extraComputations' => ['TOLLS'],
                    // Deep-merge over connector-set routeModifiers.
                    'routeModifiers' => ['avoidIndoor' => true],
                ],
                headers: ['X-Goog-User-Project' => 'proj-123'],
            ),
        ));

        self::assertNotNull($recorder->captured);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $recorder->captured->getBody(), true) ?? [];
        self::assertSame(['TOLLS'], $body['extraComputations']);
        self::assertSame(
            ['avoidTolls' => true, 'avoidIndoor' => true],
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
        yield '400 + ErrorInfo API_KEY_INVALID → AuthFailed' => [
            400,
            ['error' => ['status' => 'INVALID_ARGUMENT', 'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID', 'domain' => 'googleapis.com']]]],
            ProviderCode::AuthFailed,
        ];
        yield '400 + ErrorInfo RATE_LIMIT_EXCEEDED → RateLimited' => [
            400,
            ['error' => ['details' => [['reason' => 'RATE_LIMIT_EXCEEDED', 'domain' => 'googleapis.com']]]],
            ProviderCode::RateLimited,
        ];
        yield '500 → ProviderUnavailable' => [500, null, ProviderCode::ProviderUnavailable];
        yield '503 → ProviderUnavailable' => [503, null, ProviderCode::ProviderUnavailable];
        yield '418 → Unknown' => [418, null, ProviderCode::Unknown];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('mapVendorErrorCases')]
    public function matrixMapsVendorErrorsToProviderCode(int $status, ?array $body, ProviderCode $expected): void
    {
        $client = self::respondingClient(new Response($status, [], $body === null ? '' : (string) json_encode($body)));
        $factory = new HttpFactory();
        $connector = new GoogleMatrixConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertSame($status, $error->statusCode);
            self::assertSame($expected, $error->providerCode);
        }
    }

    #[Test]
    public function matrixSurfacesRetryAfterInProviderMessageAndCauseWithoutStructuredField(): void
    {
        $vendor = ['error' => ['message' => 'too many requests']];
        $client = self::respondingClient(new Response(
            429,
            ['Retry-After' => '30', 'Content-Type' => 'application/json'],
            (string) json_encode($vendor),
        ));
        $factory = new HttpFactory();
        $connector = new GoogleMatrixConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
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
