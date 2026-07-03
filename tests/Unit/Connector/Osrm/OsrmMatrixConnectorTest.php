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
use Thinwrap\Location\Connector\Osrm\OsrmMatrixConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;

final class OsrmMatrixConnectorTest extends TestCase
{
    #[Test]
    public function constructorThrowsWhenBaseUrlIsEmpty(): void
    {
        $factory = new HttpFactory();
        $client = self::respondingClient(new Response(200, [], '{}'));

        try {
            new OsrmMatrixConnector(
                new OsrmConfig(baseUrl: ''),
                $client,
                $factory,
                $factory,
            );
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertNull($error->statusCode);
            self::assertSame(ProviderCode::InvalidRequest, $error->providerCode);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString('baseUrl', $error->providerMessage);
        }
    }

    #[Test]
    public function getProviderIdReturnsOsrm(): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(200, [], '{}')));

        self::assertSame('osrm', $connector->getProviderId());
    }

    #[Test]
    public function matrixReturnsCorrectCellsForSingleCell(): void
    {
        $json = (string) json_encode([
            'code' => 'Ok',
            'durations' => [[300]],
            'distances' => [[5000]],
        ]);
        $connector = self::makeConnector(self::respondingClient(new Response(200, ['Content-Type' => 'application/json'], $json)));

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(32.08, 34.78)],
            destinations: [new LatLng(32.10, 34.80)],
        ));

        self::assertCount(1, $result->cells);
        self::assertSame(0, $result->cells[0]->originIndex);
        self::assertSame(0, $result->cells[0]->destinationIndex);
        self::assertSame(300.0, $result->cells[0]->durationSeconds);
        self::assertSame(5000.0, $result->cells[0]->distanceMeters);
    }

    #[Test]
    public function matrixFlattens2dResponseToCellsOriginMajor(): void
    {
        // 2 origins × 2 destinations → 4 cells, origin-major.
        $json = (string) json_encode([
            'code' => 'Ok',
            'durations' => [
                [0, 120],
                [130, 0],
            ],
            'distances' => [
                [0, 2000],
                [2100, 0],
            ],
        ]);
        $connector = self::makeConnector(self::respondingClient(new Response(200, ['Content-Type' => 'application/json'], $json)));

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(40.7128, -74.006), new LatLng(40.758, -73.9855)],
            destinations: [new LatLng(40.7484, -73.9856), new LatLng(40.7614, -73.9776)],
        ));

        self::assertCount(4, $result->cells);
        // (0,0)
        self::assertSame(0, $result->cells[0]->originIndex);
        self::assertSame(0, $result->cells[0]->destinationIndex);
        self::assertSame(0.0, $result->cells[0]->durationSeconds);
        self::assertSame(0.0, $result->cells[0]->distanceMeters);
        // (0,1)
        self::assertSame(0, $result->cells[1]->originIndex);
        self::assertSame(1, $result->cells[1]->destinationIndex);
        self::assertSame(120.0, $result->cells[1]->durationSeconds);
        self::assertSame(2000.0, $result->cells[1]->distanceMeters);
        // (1,0)
        self::assertSame(1, $result->cells[2]->originIndex);
        self::assertSame(0, $result->cells[2]->destinationIndex);
        self::assertSame(130.0, $result->cells[2]->durationSeconds);
        self::assertSame(2100.0, $result->cells[2]->distanceMeters);
        // (1,1)
        self::assertSame(1, $result->cells[3]->originIndex);
        self::assertSame(1, $result->cells[3]->destinationIndex);
        self::assertIsArray($result->raw);
    }

    #[Test]
    public function matrixCallsTableV1WithSourcesAndDestinationsIndices(): void
    {
        $recorder = self::recordingClient(self::okMatrixResponse(2, 2));
        $connector = self::makeConnector($recorder, baseUrl: 'https://router.project-osrm.org');

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(40.7128, -74.006), new LatLng(40.758, -73.9855)],
            destinations: [new LatLng(40.7484, -73.9856), new LatLng(40.7614, -73.9776)],
        ));

        self::assertNotNull($recorder->captured);
        self::assertSame('GET', $recorder->captured->getMethod());
        $uri = (string) $recorder->captured->getUri();
        $decoded = rawurldecode($uri);
        self::assertStringStartsWith('https://router.project-osrm.org/table/v1/driving/', $uri);
        // All 4 coords joined as `lng,lat;lng,lat;lng,lat;lng,lat` in input order.
        self::assertStringContainsString('-74.006,40.7128;-73.9855,40.758;-73.9856,40.7484;-73.9776,40.7614', $decoded);
        // sources = first N indices; destinations = remaining (URL-encoded semicolons).
        self::assertStringContainsString('sources=0%3B1', $uri);
        self::assertStringContainsString('destinations=2%3B3', $uri);
    }

    #[Test]
    public function matrixSetsAnnotationsInvariantOnQueryString(): void
    {
        $recorder = self::recordingClient(self::okMatrixResponse(1, 1));
        $connector = self::makeConnector($recorder);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
        ));

        self::assertNotNull($recorder->captured);
        $uri = (string) $recorder->captured->getUri();
        self::assertStringContainsString('annotations=duration%2Cdistance', $uri);
    }

    #[Test]
    public function matrixForcesAnnotationsInvariantEvenWhenPassthroughOverrides(): void
    {
        $recorder = self::recordingClient(self::okMatrixResponse(1, 1));
        $connector = self::makeConnector($recorder);

        // Consumer attempts to override `annotations` — should be silently overwritten.
        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
            passthrough: new Passthrough(query: ['annotations' => 'duration']),
        ));

        self::assertNotNull($recorder->captured);
        $uri = (string) $recorder->captured->getUri();
        // The annotations key must reflect the connector invariant, not the consumer's override.
        self::assertMatchesRegularExpression('/[?&]annotations=duration%2Cdistance(&|$)/', $uri);
        self::assertStringNotContainsString('annotations=duration&', $uri);
        // Per the baseline-coverage rule: invariant wins.
    }

    #[Test]
    public function matrixDoesNotSendAuthHeadersOrQuery(): void
    {
        $recorder = self::recordingClient(self::okMatrixResponse(1, 1));
        $connector = self::makeConnector($recorder);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
        ));

        self::assertNotNull($recorder->captured);
        $uri = (string) $recorder->captured->getUri();
        // No common auth params (consumers self-host OSRM).
        self::assertStringNotContainsString('access_token=', $uri);
        self::assertStringNotContainsString('apiKey=', $uri);
        self::assertStringNotContainsString('api_key=', $uri);
        self::assertStringNotContainsString('key=', $uri);
        self::assertSame('', $recorder->captured->getHeaderLine('Authorization'));
    }

    #[Test]
    public function matrixMergesPassthroughQueryAndHeaders(): void
    {
        $recorder = self::recordingClient(self::okMatrixResponse(1, 1));
        $connector = self::makeConnector($recorder);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
            passthrough: new Passthrough(
                query: ['fallback_speed' => '50'],
                headers: ['X-Custom' => 'val'],
            ),
        ));

        self::assertNotNull($recorder->captured);
        self::assertStringContainsString('fallback_speed=50', (string) $recorder->captured->getUri());
        self::assertSame('val', $recorder->captured->getHeaderLine('X-Custom'));
    }

    #[Test]
    public function matrixMapsTravelModesToOsrmProfile(): void
    {
        foreach ([
            [TravelMode::Walking, 'walking'],
            [TravelMode::Cycling, 'cycling'],
            [TravelMode::Driving, 'driving'],
        ] as [$mode, $expected]) {
            $recorder = self::recordingClient(self::okMatrixResponse(1, 1));
            $connector = self::makeConnector($recorder);
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
                travelMode: $mode,
            ));

            self::assertNotNull($recorder->captured);
            self::assertStringContainsString("/table/v1/{$expected}/", (string) $recorder->captured->getUri());
        }
    }

    #[Test]
    public function matrixPreFlightRaisesUnsupportedFieldForDepartureTime(): void
    {
        $recorder = self::recordingClient(self::okMatrixResponse(1, 1));
        $connector = self::makeConnector($recorder);

        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
                departureTime: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertNull($error->statusCode, 'Pre-flight errors carry statusCode: null');
            self::assertSame(ProviderCode::UnsupportedField, $error->providerCode);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString('departureTime', $error->providerMessage);
        }

        // No HTTP call was made.
        self::assertNull($recorder->captured);
    }

    #[Test]
    public function matrixPreFlightRaisesUnsupportedOptionForAvoidTolls(): void
    {
        $recorder = self::recordingClient(self::okMatrixResponse(1, 1));
        $connector = self::makeConnector($recorder);

        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
                avoidTolls: true,
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertNull($error->statusCode);
            self::assertSame(ProviderCode::UnsupportedOption, $error->providerCode);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString('avoidTolls', $error->providerMessage);
        }

        self::assertNull($recorder->captured);
    }

    #[Test]
    public function matrixThrowsWhenEnvelopeCodeIsNoTable(): void
    {
        $json = (string) json_encode(['code' => 'NoTable', 'message' => 'No matrix could be computed']);
        $connector = self::makeConnector(self::respondingClient(new Response(200, [], $json)));

        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertSame(ProviderCode::InvalidRequest, $error->providerCode);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString('NoTable', $error->providerMessage);
        }
    }

    #[Test]
    public function matrixThrowsWhenEnvelopeCodeIsInvalidQuery(): void
    {
        $json = (string) json_encode(['code' => 'InvalidQuery']);
        $connector = self::makeConnector(self::respondingClient(new Response(200, [], $json)));

        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertSame(ProviderCode::InvalidRequest, $error->providerCode);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString('InvalidQuery', $error->providerMessage);
        }
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>|null, ProviderCode}>
     */
    public static function mapVendorErrorCases(): iterable
    {
        yield '404 → InvalidRequest' => [404, ['message' => 'not found'], ProviderCode::InvalidRequest];
        yield '400 → InvalidRequest' => [400, ['message' => 'bad input'], ProviderCode::InvalidRequest];
        yield '500 → ProviderUnavailable' => [500, null, ProviderCode::ProviderUnavailable];
        yield '503 → ProviderUnavailable' => [503, null, ProviderCode::ProviderUnavailable];
        yield '401 → AuthFailed (reverse-proxy)' => [401, null, ProviderCode::AuthFailed];
        yield '403 → AuthFailed (reverse-proxy)' => [403, null, ProviderCode::AuthFailed];
        yield '429 → RateLimited (reverse-proxy)' => [429, null, ProviderCode::RateLimited];
        yield '418 → Unknown' => [418, null, ProviderCode::Unknown];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('mapVendorErrorCases')]
    public function matrixMapsVendorErrorsToProviderCode(int $status, ?array $body, ProviderCode $expected): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(
            $status,
            [],
            $body === null ? '' : (string) json_encode($body),
        )));

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
        $vendor = ['message' => 'too many requests'];
        $client = self::respondingClient(new Response(
            429,
            ['Retry-After' => '45', 'Content-Type' => 'application/json'],
            (string) json_encode($vendor),
        ));
        $connector = self::makeConnector($client);

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
            self::assertStringContainsString('retry after 45 seconds', $error->providerMessage);
            self::assertStringContainsString('too many requests', $error->providerMessage);

            self::assertIsArray($error->cause);
            self::assertSame('45', $error->cause['retryAfter']);
            // No `retryAfterSeconds` field by design.
            self::assertFalse(property_exists($error, 'retryAfterSeconds'));
        }
    }

    #[Test]
    public function matrixThrowsWhenOriginsOrDestinationsAreEmpty(): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(200, [], '{}')));

        try {
            $connector->matrix(new MatrixOptions(
                origins: [],
                destinations: [new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertSame(ProviderCode::InvalidRequest, $error->providerCode);
        }
    }

    private static function makeConnector(
        ClientInterface $client,
        string $baseUrl = 'https://router.project-osrm.org',
    ): OsrmMatrixConnector {
        $factory = new HttpFactory();

        return new OsrmMatrixConnector(
            new OsrmConfig(baseUrl: $baseUrl),
            $client,
            $factory,
            $factory,
        );
    }

    private static function okMatrixResponse(int $origins, int $destinations): ResponseInterface
    {
        $durations = [];
        $distances = [];
        for ($o = 0; $o < $origins; $o++) {
            $durations[] = array_fill(0, $destinations, 1);
            $distances[] = array_fill(0, $destinations, 1);
        }

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode([
                'code' => 'Ok',
                'durations' => $durations,
                'distances' => $distances,
            ]),
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
