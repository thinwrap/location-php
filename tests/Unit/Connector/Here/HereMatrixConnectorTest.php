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
use Thinwrap\Location\Connector\Here\HereMatrixConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\Enum\ProviderCode;

/**
 * HereMatrixConnector spec.
 *
 * Covers (submit→poll→retrieve cycle), (backoff + deadline),
 * (timeoutMs override), (timeout raise with `cause.matrixId`), (2D
 * flatten), (mapVendorError), (sequential-call spec pattern).
 */
final class HereMatrixConnectorTest extends TestCase
{
    #[Test]
    public function getProviderIdReturnsHere(): void
    {
        $connector = self::makeConnector(self::sequencedClient([new Response(200, [], '')]));
        self::assertSame('here', $connector->getProviderId());
    }

    #[Test]
    public function matrixRunsSubmitPollRetrieveCycleAndFlattens2dGrid(): void
    {
        // Real HERE v8 async shapes (verified live 2026-07-20):
        //   poll completion  = HTTP 303 + `Location: <resultUrl>` + body with resultUrl
        //   retrieve step 3a = resultUrl → HTTP 303 → `Location: <pre-signed S3 URL>`
        //   retrieve step 3b = S3 → HTTP 200 gzip matrix body
        // The resolved hosts below are SAMPLE data — the connector reads them at
        // runtime from the responses; nothing here is hardcoded into the code.
        $submitJson = (string) json_encode([
            'matrixId' => 'm1',
            'statusUrl' => 'https://matrix.router.hereapi.com/v8/status/m1',
        ]);
        $pendingJson = (string) json_encode(['status' => 'inProgress']);
        // Completion resultUrl lives on the aws-eu-west-1.*.hereapi.com host —
        // covered by the `*.hereapi.com` allow-list.
        $resultUrl = 'https://aws-eu-west-1.matrix.router.hereapi.com/v8/matrix/m1/result';
        $completedJson = (string) json_encode([
            'matrixId' => 'm1',
            'status' => 'completed',
            'resultUrl' => $resultUrl,
        ]);
        // Pre-signed S3 object URL (non-HERE host, query-signed) — sample.
        $s3Url = 'https://s3.eu-west-1.amazonaws.com/matrix-results/m1.json.gz'
            . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=deadbeef';
        // HERE serves the matrix result gzip-compressed.
        $resultGz = (string) gzencode((string) json_encode([
            'matrixId' => 'm1',
            'matrix' => [
                'numOrigins' => 2,
                'numDestinations' => 2,
                'travelTimes' => [0, 120, 130, 0],
                'distances' => [0, 2000, 2100, 0],
            ],
        ]));

        $client = self::sequencedClient([
            new Response(200, [], $submitJson),
            new Response(200, [], $pendingJson),
            new Response(303, ['Location' => $resultUrl], $completedJson),
            new Response(303, ['Location' => $s3Url], ''),
            new Response(200, ['Content-Encoding' => 'gzip'], $resultGz),
        ]);

        $connector = self::makeConnector($client);

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(52.53, 13.38), new LatLng(52.52, 13.40)],
            destinations: [new LatLng(52.51, 13.39), new LatLng(52.50, 13.41)],
        ));

        // submit + 2 polls + resultUrl GET + S3 hop = 5 calls.
        self::assertCount(5, $client->captured);

        // Call 1: submit (POST, async=true).
        $submit = $client->captured[0];
        self::assertSame('POST', $submit->getMethod());
        self::assertStringStartsWith(
            'https://matrix.router.hereapi.com/v8/matrix',
            (string) $submit->getUri(),
        );
        self::assertStringContainsString('async=true', (string) $submit->getUri());
        self::assertStringContainsString('apiKey=', (string) $submit->getUri());

        // Call 2 + 3: poll (GET to statusUrl); completion arrives as a 303.
        self::assertSame('GET', $client->captured[1]->getMethod());
        self::assertStringStartsWith(
            'https://matrix.router.hereapi.com/v8/status/m1',
            (string) $client->captured[1]->getUri(),
        );
        self::assertSame('GET', $client->captured[2]->getMethod());

        // Call 4: retrieve resultUrl — GET with the apiKey AND Accept-Encoding: gzip.
        $retrieve = $client->captured[3];
        self::assertSame('GET', $retrieve->getMethod());
        self::assertStringStartsWith($resultUrl, (string) $retrieve->getUri());
        self::assertStringContainsString('apiKey=', (string) $retrieve->getUri());
        self::assertSame('gzip', $retrieve->getHeaderLine('Accept-Encoding'));

        // Call 5: the S3 hop — plain GET, NO HERE apiKey (self-signed, non-HERE host).
        $s3 = $client->captured[4];
        self::assertSame('GET', $s3->getMethod());
        self::assertStringStartsWith('https://s3.eu-west-1.amazonaws.com/', (string) $s3->getUri());
        self::assertStringNotContainsString('apiKey', (string) $s3->getUri());
        self::assertStringNotContainsString('test-here-key', (string) $s3->getUri());
        self::assertSame('gzip', $s3->getHeaderLine('Accept-Encoding'));

        // Cells flattened: 2x2 grid, 4 entries. distances are METERS and
        // travelTimes are SECONDS — used as-is (no conversion).
        self::assertCount(4, $result->cells);
        self::assertSame(0, $result->cells[0]->originIndex);
        self::assertSame(0, $result->cells[0]->destinationIndex);
        self::assertSame(0.0, $result->cells[0]->durationSeconds);
        self::assertSame(0.0, $result->cells[0]->distanceMeters);
        self::assertSame(0, $result->cells[1]->originIndex);
        self::assertSame(1, $result->cells[1]->destinationIndex);
        self::assertSame(120.0, $result->cells[1]->durationSeconds);
        self::assertSame(2000.0, $result->cells[1]->distanceMeters);
        self::assertSame(1, $result->cells[2]->originIndex);
        self::assertSame(0, $result->cells[2]->destinationIndex);
        self::assertSame(130.0, $result->cells[2]->durationSeconds);
        self::assertSame(2100.0, $result->cells[2]->distanceMeters);
    }

    #[Test]
    public function matrixRetrieveHandlesDirect200PlainBodyWithoutS3Hop(): void
    {
        // Some responses return the matrix inline as a plain 200 (no S3 hop,
        // no gzip) — the connector must handle that shape too. Also locks in
        // the units contract: distances METERS, travelTimes SECONDS, as-is.
        $client = self::sequencedClient([
            new Response(200, [], (string) json_encode([
                'matrixId' => 'm-direct',
                'statusUrl' => 'https://matrix.router.hereapi.com/v8/status/m-direct',
            ])),
            new Response(
                303,
                ['Location' => 'https://matrix.router.hereapi.com/v8/matrix/m-direct/result'],
                (string) json_encode([
                    'status' => 'completed',
                    'resultUrl' => 'https://matrix.router.hereapi.com/v8/matrix/m-direct/result',
                ]),
            ),
            new Response(200, [], (string) json_encode([
                'matrix' => [
                    'numOrigins' => 1,
                    'numDestinations' => 1,
                    'travelTimes' => [5427],
                    'distances' => [109144],
                ],
            ])),
        ]);
        $connector = self::makeConnector($client, static function (int $_us): void {});

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(40.7484, -73.9857)],
            destinations: [new LatLng(41.1792, -73.1952)],
        ));

        // submit + poll(303) + retrieve(direct 200) = 3 calls; NO S3 hop.
        self::assertCount(3, $client->captured);
        self::assertCount(1, $result->cells);
        self::assertSame(109144.0, $result->cells[0]->distanceMeters);
        self::assertSame(5427.0, $result->cells[0]->durationSeconds);
    }

    #[Test]
    public function matrixRetrieveDecompressesGzipDetectedByMagicBytes(): void
    {
        // gzip body served WITHOUT a Content-Encoding header — decompression
        // must fall back to sniffing the 0x1f 0x8b gzip magic bytes.
        $resultGz = (string) gzencode((string) json_encode([
            'matrix' => [
                'numOrigins' => 1,
                'numDestinations' => 1,
                'travelTimes' => [5427],
                'distances' => [109144],
            ],
        ]));
        $client = self::sequencedClient([
            new Response(200, [], (string) json_encode([
                'matrixId' => 'm-gz',
                'statusUrl' => 'https://matrix.router.hereapi.com/v8/status/m-gz',
            ])),
            new Response(
                303,
                ['Location' => 'https://aws-eu-west-1.matrix.router.hereapi.com/v8/matrix/m-gz/result'],
                (string) json_encode([
                    'status' => 'completed',
                    'resultUrl' => 'https://aws-eu-west-1.matrix.router.hereapi.com/v8/matrix/m-gz/result',
                ]),
            ),
            new Response(200, [], $resultGz),
        ]);
        $connector = self::makeConnector($client, static function (int $_us): void {});

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(40.7484, -73.9857)],
            destinations: [new LatLng(41.1792, -73.1952)],
        ));

        self::assertCount(1, $result->cells);
        self::assertSame(109144.0, $result->cells[0]->distanceMeters);
        self::assertSame(5427.0, $result->cells[0]->durationSeconds);
    }

    #[Test]
    public function matrixSendsOriginsDestinationsAndAutoCircleRegionInSubmitBody(): void
    {
        $client = self::sequencedClient([
            new Response(200, [], (string) json_encode([
                'matrixId' => 'mX',
                'statusUrl' => 'https://matrix.router.hereapi.com/v8/status/mX',
            ])),
            new Response(200, [], (string) json_encode([
                'status' => 'completed',
                'resultUrl' => 'https://matrix.router.hereapi.com/v8/result/mX',
            ])),
            new Response(200, [], (string) json_encode([
                'matrix' => ['numDestinations' => 1, 'travelTimes' => [0], 'distances' => [0]],
            ])),
        ]);
        $connector = self::makeConnector($client);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0.0, 0.0)],
            destinations: [new LatLng(1.0, 1.0)],
        ));

        $submit = $client->captured[0];
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $submit->getBody(), true) ?? [];
        self::assertSame(['type' => 'autoCircle'], $body['regionDefinition']);
        self::assertSame(['travelTimes', 'distances'], $body['matrixAttributes']);
        self::assertIsArray($body['origins']);
        self::assertCount(1, $body['origins']);
        // Note: the connector emits float lat/lng, but `json_encode(0.0)` →
        // `"0"` (no PRESERVE_ZERO_FRACTION) so the JSON round-trip decodes whole
        // values back to PHP int. Assert the decoded (int) shape — the wire bytes
        // are identical either way.
        self::assertSame(['lat' => 0, 'lng' => 0], $body['origins'][0]);
        self::assertIsArray($body['destinations']);
        self::assertSame(['lat' => 1, 'lng' => 1], $body['destinations'][0]);
    }

    #[Test]
    public function matrixRaisesPollingTimeoutWithCauseMatrixIdAndStatusUrl(): void
    {
        // Submit once, then a long stream of pending responses to exhaust the
        // 50 ms deadline.
        $responses = [new Response(200, [], (string) json_encode([
            'matrixId' => 'stuck-job',
            'statusUrl' => 'https://matrix.router.hereapi.com/v8/status/stuck-job',
        ]))];
        for ($i = 0; $i < 200; $i++) {
            $responses[] = new Response(200, [], (string) json_encode(['status' => 'inProgress']));
        }
        $client = self::sequencedClient($responses);

        // No-op sleep + 50 ms deadline => the loop will exit on the first
        // microtime check after a few iterations.
        $connector = self::makeConnector($client, static function (int $_us): void {});

        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
                passthrough: new Passthrough(body: ['timeoutMs' => 50]),
            ));
            self::fail('Expected ConnectorError for polling timeout');
        } catch (ConnectorError $error) {
            self::assertSame(ProviderCode::MatrixPollingTimeout, $error->providerCode);
            self::assertNull($error->statusCode);
            self::assertIsArray($error->cause);
            self::assertSame('stuck-job', $error->cause['matrixId']);
            self::assertSame('https://matrix.router.hereapi.com/v8/status/stuck-job', $error->cause['statusUrl']);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString('stuck-job', $error->providerMessage);
        }
    }

    #[Test]
    public function matrixDeadlineCanBeShortenedViaPassthroughBodyTimeoutMs(): void
    {
        // Establish that the consumer-provided `timeoutMs` is honored: a tiny
        // deadline (1 ms) blows up immediately on the first deadline-check.
        $responses = [new Response(200, [], (string) json_encode([
            'matrixId' => 'm-fast',
            'statusUrl' => 'https://matrix.router.hereapi.com/v8/status/m-fast',
        ]))];
        for ($i = 0; $i < 50; $i++) {
            $responses[] = new Response(200, [], (string) json_encode(['status' => 'pending']));
        }
        $client = self::sequencedClient($responses);

        // Use a real (but tiny) sleep so the deadline check sees elapsed time.
        $connector = self::makeConnector($client, static function (int $us): void {
            // Sleep at most 2 ms to keep the test fast.
            \usleep(min($us, 2000));
        });

        $this->expectException(ConnectorError::class);
        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
                passthrough: new Passthrough(body: ['timeoutMs' => 1]),
            ));
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::MatrixPollingTimeout, $e->providerCode);
            throw $e;
        }
    }

    #[Test]
    public function matrixTimeoutMsPassthroughIsStrippedFromSubmitBody(): void
    {
        $client = self::sequencedClient([
            new Response(200, [], (string) json_encode([
                'matrixId' => 'm-strip',
                'statusUrl' => 'https://matrix.router.hereapi.com/v8/status/m-strip',
            ])),
            new Response(200, [], (string) json_encode([
                'status' => 'completed',
                'resultUrl' => 'https://matrix.router.hereapi.com/v8/result/m-strip',
            ])),
            new Response(200, [], (string) json_encode([
                'matrix' => ['numDestinations' => 1, 'travelTimes' => [0], 'distances' => [0]],
            ])),
        ]);
        $connector = self::makeConnector($client);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
            passthrough: new Passthrough(body: ['timeoutMs' => 30_000, 'extraVendorField' => 'keep']),
        ));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->captured[0]->getBody(), true) ?? [];
        self::assertArrayNotHasKey('timeoutMs', $body);
        self::assertSame('keep', $body['extraVendorField']);
    }

    #[Test]
    public function matrixThrowsOnFailedJobState(): void
    {
        $client = self::sequencedClient([
            new Response(200, [], (string) json_encode([
                'matrixId' => 'm-fail',
                'statusUrl' => 'https://matrix.router.hereapi.com/v8/status/m-fail',
            ])),
            new Response(200, [], (string) json_encode([
                'status' => 'failed',
                'error' => ['message' => 'job crashed'],
            ])),
        ]);
        $connector = self::makeConnector($client, static function (int $_us): void {});

        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError for failed job state');
        } catch (ConnectorError $error) {
            self::assertSame(ProviderCode::ProviderUnavailable, $error->providerCode);
            self::assertIsArray($error->cause);
            self::assertSame('failed', $error->cause['status']);
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
        yield '500 → ProviderUnavailable' => [500, ProviderCode::ProviderUnavailable];
        yield '503 → ProviderUnavailable' => [503, ProviderCode::ProviderUnavailable];
        yield '418 → Unknown' => [418, ProviderCode::Unknown];
    }

    #[Test]
    #[DataProvider('mapVendorErrorCases')]
    public function matrixMapsSubmitVendorErrorsToProviderCode(int $status, ProviderCode $expected): void
    {
        $body = (string) json_encode(['title' => 'bad', 'cause' => 'something went wrong']);
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
        $vendor = ['title' => 'Too Many Requests', 'cause' => 'over quota'];
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function untrustedStatusUrlCases(): iterable
    {
        // Non-hereapi host — key-exfiltration vector the guard exists to block.
        yield 'wrong host' => ['https://evil.example.com/v8/status/m1'];
        // Non-https scheme.
        yield 'non-https scheme' => ['http://matrix.router.hereapi.com/v8/status/m1'];
        // Malformed URL with no parseable host.
        yield 'malformed url' => ['not-a-url'];
    }

    #[Test]
    #[DataProvider('untrustedStatusUrlCases')]
    public function matrixRejectsUntrustedStatusUrl(string $statusUrl): void
    {
        // submit() returns a tampered statusUrl → validateProviderUrl('statusUrl') throws.
        $client = self::sequencedClient([
            new Response(200, [], (string) json_encode([
                'matrixId' => 'm1',
                'statusUrl' => $statusUrl,
            ])),
        ]);
        $connector = self::makeConnector($client);

        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError for untrusted statusUrl');
        } catch (ConnectorError $error) {
            self::assertSame(ProviderCode::InvalidRequest, $error->providerCode);
            self::assertNull($error->statusCode);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString('statusUrl', $error->providerMessage);
        }
    }

    #[Test]
    public function matrixRejectsUntrustedResultUrl(): void
    {
        // A trusted statusUrl that polls to a completed state carrying a tampered
        // resultUrl → validateProviderUrl('resultUrl') throws inside poll().
        $client = self::sequencedClient([
            new Response(200, [], (string) json_encode([
                'matrixId' => 'm1',
                'statusUrl' => 'https://matrix.router.hereapi.com/v8/status/m1',
            ])),
            new Response(200, [], (string) json_encode([
                'status' => 'completed',
                'resultUrl' => 'https://evil.example.com/v8/result/m1',
            ])),
        ]);
        $connector = self::makeConnector($client, static function (int $_us): void {});

        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError for untrusted resultUrl');
        } catch (ConnectorError $error) {
            self::assertSame(ProviderCode::InvalidRequest, $error->providerCode);
            self::assertNull($error->statusCode);
            self::assertNotNull($error->providerMessage);
            self::assertStringContainsString('resultUrl', $error->providerMessage);
        }
    }

    /**
     * Sequential PSR-18 client that returns the supplied responses in order and
     * records each outbound request.
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
                    // Exhausted: return a generic 200 to avoid a fatal so the
                    // test can assert via the captured-call count.
                    return new Response(200, [], '');
                }

                return $next;
            }
        };
    }

    /**
     * @param (callable(int): void)|null $sleepFn
     */
    private static function makeConnector(ClientInterface $client, ?callable $sleepFn = null): HereMatrixConnector
    {
        $factory = new HttpFactory();

        return new HereMatrixConnector(
            new HereConfig(apiKey: 'test-here-key'),
            $client,
            $factory,
            $factory,
            // Default: no-op sleep so tests don't actually wait. Individual
            // tests that exercise real time-elapsed deadlines opt in.
            $sleepFn ?? static function (int $_us): void {},
        );
    }
}
