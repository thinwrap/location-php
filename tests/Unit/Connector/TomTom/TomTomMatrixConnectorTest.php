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
use Thinwrap\Location\Connector\TomTom\TomTomMatrixConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\Enum\ProviderCode;

/**
 * TomTomMatrixConnector spec.
 *
 * Covers (cell-count threshold dispatch), (sync POST + flatten),
 * (async submit→poll→retrieve + polling timeout), (timeoutMs
 * override), (cell normalization), (mapVendorError), (spec
 * with sync + async + timeout coverage).
 */
final class TomTomMatrixConnectorTest extends TestCase
{
    #[Test]
    public function getProviderIdReturnsTomtom(): void
    {
        $connector = self::makeConnector(self::sequencedClient([new Response(200, [], '')]));
        self::assertSame('tomtom', $connector->getProviderId());
    }

    #[Test]
    public function matrixDispatchesSyncWhenCellCountIsAtOrBelowThreshold(): void
    {
        $json = (string) json_encode([
            'data' => [
                [
                    'originIndex' => 0,
                    'destinationIndex' => 0,
                    'routeSummary' => ['lengthInMeters' => 5000, 'travelTimeInSeconds' => 300],
                ],
                [
                    'originIndex' => 0,
                    'destinationIndex' => 1,
                    'routeSummary' => ['lengthInMeters' => 7500, 'travelTimeInSeconds' => 400],
                ],
            ],
        ]);

        $client = self::sequencedClient([new Response(200, [], $json)]);
        $connector = self::makeConnector($client);

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(32.08, 34.78)],
            destinations: [new LatLng(32.10, 34.80), new LatLng(32.11, 34.85)],
        ));

        // Single sync POST.
        self::assertCount(1, $client->captured);
        self::assertSame('POST', $client->captured[0]->getMethod());
        self::assertStringStartsWith(
            'https://api.tomtom.com/routing/matrix/2',
            (string) $client->captured[0]->getUri(),
        );
        // Sync path does NOT include `/async`.
        self::assertStringNotContainsString('/async', (string) $client->captured[0]->getUri());
        self::assertStringContainsString('key=test-tomtom-key', (string) $client->captured[0]->getUri());

        self::assertCount(2, $result->cells);
        self::assertSame(0, $result->cells[0]->originIndex);
        self::assertSame(0, $result->cells[0]->destinationIndex);
        self::assertSame(5000.0, $result->cells[0]->distanceMeters);
        self::assertSame(300.0, $result->cells[0]->durationSeconds);
        self::assertSame(0, $result->cells[1]->originIndex);
        self::assertSame(1, $result->cells[1]->destinationIndex);
        self::assertSame(7500.0, $result->cells[1]->distanceMeters);
        self::assertSame(400.0, $result->cells[1]->durationSeconds);
    }

    #[Test]
    public function matrixSyncBodyContainsOriginsDestinationsAndTravelMode(): void
    {
        $client = self::sequencedClient([
            new Response(200, [], (string) json_encode(['data' => []])),
        ]);
        $connector = self::makeConnector($client);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(1.0, 2.0)],
            destinations: [new LatLng(3.0, 4.0)],
        ));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->captured[0]->getBody(), true) ?? [];
        // Note: `json_encode(1.0)` → `"1"` (no PRESERVE_ZERO_FRACTION), so the
        // JSON round-trip decodes whole float values back to PHP int. Assert the
        // decoded (int) shape — the wire bytes are identical either way.
        self::assertIsArray($body['origins']);
        self::assertSame(['point' => ['latitude' => 1, 'longitude' => 2]], $body['origins'][0]);
        self::assertIsArray($body['destinations']);
        self::assertSame(['point' => ['latitude' => 3, 'longitude' => 4]], $body['destinations'][0]);
        self::assertIsArray($body['options']);
        self::assertSame('car', $body['options']['travelMode']);
    }

    #[Test]
    public function matrixDispatchesAsyncWhenCellCountExceedsThreshold(): void
    {
        // 51 × 51 = 2601 cells > 2500 → async path.
        $origins = [];
        $destinations = [];
        for ($i = 0; $i < 51; $i++) {
            $origins[] = new LatLng(32.0 + $i * 0.001, 34.0);
            $destinations[] = new LatLng(33.0 + $i * 0.001, 35.0);
        }

        $submitJson = (string) json_encode(['jobId' => 'job-abc']);
        $pendingJson = (string) json_encode(['state' => 'Running']);
        $succeededJson = (string) json_encode(['state' => 'Succeeded']);
        $resultJson = (string) json_encode([
            'data' => [
                [
                    'originIndex' => 0,
                    'destinationIndex' => 0,
                    'routeSummary' => ['lengthInMeters' => 100.0, 'travelTimeInSeconds' => 50.0],
                ],
            ],
        ]);

        $client = self::sequencedClient([
            new Response(200, [], $submitJson),
            new Response(200, [], $pendingJson),
            new Response(200, [], $succeededJson),
            new Response(200, [], $resultJson),
        ]);
        $connector = self::makeConnector($client);

        $result = $connector->matrix(new MatrixOptions(
            origins: $origins,
            destinations: $destinations,
        ));

        self::assertCount(4, $client->captured);

        // Call 1: submit to /async.
        self::assertSame('POST', $client->captured[0]->getMethod());
        self::assertStringStartsWith(
            'https://api.tomtom.com/routing/matrix/2/async',
            (string) $client->captured[0]->getUri(),
        );

        // Calls 2 & 3: poll the status URL by jobId.
        self::assertSame('GET', $client->captured[1]->getMethod());
        self::assertStringContainsString(
            '/routing/matrix/2/async/job-abc',
            (string) $client->captured[1]->getUri(),
        );
        self::assertSame('GET', $client->captured[2]->getMethod());

        // Call 4: retrieve.
        self::assertSame('GET', $client->captured[3]->getMethod());
        self::assertStringContainsString(
            '/routing/matrix/2/async/job-abc/result',
            (string) $client->captured[3]->getUri(),
        );

        self::assertCount(1, $result->cells);
        self::assertSame(100.0, $result->cells[0]->distanceMeters);
        self::assertSame(50.0, $result->cells[0]->durationSeconds);
    }

    #[Test]
    public function matrixRaisesPollingTimeoutWithCauseJobIdOnDeadlineExpiry(): void
    {
        $origins = [];
        $destinations = [];
        for ($i = 0; $i < 51; $i++) {
            $origins[] = new LatLng(32.0 + $i * 0.001, 34.0);
            $destinations[] = new LatLng(33.0 + $i * 0.001, 35.0);
        }

        $responses = [new Response(200, [], (string) json_encode(['jobId' => 'stuck-job']))];
        for ($i = 0; $i < 200; $i++) {
            $responses[] = new Response(200, [], (string) json_encode(['state' => 'Running']));
        }
        $client = self::sequencedClient($responses);

        // No-op sleep + 50 ms deadline => the loop exits on the first
        // microtime check after a few iterations.
        $connector = self::makeConnector($client, static function (int $_us): void {});

        try {
            $connector->matrix(new MatrixOptions(
                origins: $origins,
                destinations: $destinations,
                passthrough: new Passthrough(body: ['timeoutMs' => 50]),
            ));
            self::fail('Expected ConnectorError for polling timeout');
        } catch (ConnectorError $error) {
            self::assertSame(ProviderCode::MatrixPollingTimeout, $error->providerCode);
            self::assertNull($error->statusCode);
            self::assertIsArray($error->cause);
            self::assertSame('stuck-job', $error->cause['jobId']);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString('stuck-job', $error->providerMessage);
        }
    }

    #[Test]
    public function matrixHonorsTimeoutMsPassthroughOverride(): void
    {
        $origins = [];
        $destinations = [];
        for ($i = 0; $i < 51; $i++) {
            $origins[] = new LatLng(32.0 + $i * 0.001, 34.0);
            $destinations[] = new LatLng(33.0 + $i * 0.001, 35.0);
        }

        $responses = [new Response(200, [], (string) json_encode(['jobId' => 'job-fast']))];
        for ($i = 0; $i < 50; $i++) {
            $responses[] = new Response(200, [], (string) json_encode(['state' => 'Pending']));
        }
        $client = self::sequencedClient($responses);

        // Use a real (but tiny) sleep so the deadline check sees elapsed time.
        $connector = self::makeConnector($client, static function (int $us): void {
            \usleep(min($us, 2000));
        });

        $this->expectException(ConnectorError::class);
        try {
            $connector->matrix(new MatrixOptions(
                origins: $origins,
                destinations: $destinations,
                passthrough: new Passthrough(body: ['timeoutMs' => 1]),
            ));
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::MatrixPollingTimeout, $e->providerCode);
            throw $e;
        }
    }

    #[Test]
    public function matrixStripsTimeoutMsFromSubmitBody(): void
    {
        $origins = [];
        $destinations = [];
        for ($i = 0; $i < 51; $i++) {
            $origins[] = new LatLng(32.0 + $i * 0.001, 34.0);
            $destinations[] = new LatLng(33.0 + $i * 0.001, 35.0);
        }

        $client = self::sequencedClient([
            new Response(200, [], (string) json_encode(['jobId' => 'job-strip'])),
            new Response(200, [], (string) json_encode(['state' => 'Succeeded'])),
            new Response(200, [], (string) json_encode(['data' => []])),
        ]);
        $connector = self::makeConnector($client, static function (int $_us): void {});

        $connector->matrix(new MatrixOptions(
            origins: $origins,
            destinations: $destinations,
            passthrough: new Passthrough(body: ['timeoutMs' => 30_000, 'extraVendorField' => 'keep']),
        ));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->captured[0]->getBody(), true) ?? [];
        self::assertArrayNotHasKey('timeoutMs', $body);
        self::assertSame('keep', $body['extraVendorField']);
    }

    #[Test]
    public function matrixThrowsOnFailedAsyncJobState(): void
    {
        $origins = [];
        $destinations = [];
        for ($i = 0; $i < 51; $i++) {
            $origins[] = new LatLng(32.0 + $i * 0.001, 34.0);
            $destinations[] = new LatLng(33.0 + $i * 0.001, 35.0);
        }

        $client = self::sequencedClient([
            new Response(200, [], (string) json_encode(['jobId' => 'job-fail'])),
            new Response(200, [], (string) json_encode([
                'state' => 'Failed',
                'error' => ['message' => 'job crashed'],
            ])),
        ]);
        $connector = self::makeConnector($client, static function (int $_us): void {});

        try {
            $connector->matrix(new MatrixOptions(
                origins: $origins,
                destinations: $destinations,
            ));
            self::fail('Expected ConnectorError for failed job state');
        } catch (ConnectorError $error) {
            self::assertSame(ProviderCode::ProviderUnavailable, $error->providerCode);
            self::assertIsArray($error->cause);
            self::assertSame('Failed', $error->cause['state']);
        }
    }

    /**
     * @return iterable<string, array{int, ProviderCode}>
     */
    public static function mapVendorErrorCases(): iterable
    {
        yield '401 → AuthFailed' => [401, ProviderCode::AuthFailed];
        yield '403 → AuthFailed' => [403, ProviderCode::AuthFailed];
        yield '429 → RateLimited' => [429, ProviderCode::RateLimited];
        yield '400 → InvalidRequest' => [400, ProviderCode::InvalidRequest];
        yield '404 → InvalidRequest' => [404, ProviderCode::InvalidRequest];
        yield '500 → ProviderUnavailable' => [500, ProviderCode::ProviderUnavailable];
        yield '503 → ProviderUnavailable' => [503, ProviderCode::ProviderUnavailable];
        yield '418 → Unknown' => [418, ProviderCode::Unknown];
    }

    #[Test]
    #[DataProvider('mapVendorErrorCases')]
    public function matrixMapsSyncVendorErrorsToProviderCode(int $status, ProviderCode $expected): void
    {
        $body = (string) json_encode(['error' => ['message' => 'something went wrong']]);
        $client = self::sequencedClient([new Response($status, [], $body)]);
        $connector = self::makeConnector($client);

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
        $vendor = ['error' => ['message' => 'Too Many Requests']];
        $client = self::sequencedClient([
            new Response(
                429,
                ['Retry-After' => '15', 'Content-Type' => 'application/json'],
                (string) json_encode($vendor),
            ),
        ]);
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
            self::assertStringContainsString('retry after 15 seconds', $error->providerMessage);
            self::assertStringContainsString('Too Many Requests', $error->providerMessage);

            self::assertIsArray($error->cause);
            self::assertSame('15', $error->cause['retryAfter']);
            // No `retryAfterSeconds` field by design.
            self::assertFalse(property_exists($error, 'retryAfterSeconds'));
        }
    }

    #[Test]
    public function matrixThresholdBoundaryAt2500CellsStaysSync(): void
    {
        // 50 × 50 = 2500 → sync (boundary, inclusive).
        $origins = [];
        $destinations = [];
        for ($i = 0; $i < 50; $i++) {
            $origins[] = new LatLng(32.0 + $i * 0.001, 34.0);
            $destinations[] = new LatLng(33.0 + $i * 0.001, 35.0);
        }

        $client = self::sequencedClient([
            new Response(200, [], (string) json_encode(['data' => []])),
        ]);
        $connector = self::makeConnector($client);

        $connector->matrix(new MatrixOptions(origins: $origins, destinations: $destinations));

        self::assertCount(1, $client->captured);
        self::assertStringNotContainsString('/async', (string) $client->captured[0]->getUri());
    }

    /**
     * Sequential PSR-18 client that returns the supplied responses in order
     * and records each outbound request.
     *
     * @param list<ResponseInterface> $responses
     */
    private static function sequencedClient(array $responses): ClientInterface
    {
        return new class ($responses) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];

            /** @var list<ResponseInterface> */
            private array $responses;

            /** @param list<ResponseInterface> $responses */
            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $next = array_shift($this->responses);
                if ($next === null) {
                    return new Response(200, [], '');
                }

                return $next;
            }
        };
    }

    /**
     * @param (callable(int): void)|null $sleepFn
     */
    private static function makeConnector(ClientInterface $client, ?callable $sleepFn = null): TomTomMatrixConnector
    {
        $factory = new HttpFactory();

        return new TomTomMatrixConnector(
            new TomTomConfig(apiKey: 'test-tomtom-key'),
            $client,
            $factory,
            $factory,
            // Default: no-op sleep so tests don't actually wait.
            $sleepFn ?? static function (int $_us): void {},
        );
    }
}
