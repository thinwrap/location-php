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
use Thinwrap\Location\Connector\Esri\EsriMatrixConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\Enum\ProviderCode;

final class EsriMatrixConnectorTest extends TestCase
{
    #[Test]
    public function getProviderIdReturnsEsri(): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(200, [], '{}')));

        self::assertSame('esri', $connector->getProviderId());
    }

    #[Test]
    public function matrixReturnsNormalizedCellsWithMileAndMinuteConversion(): void
    {
        // 2 origins × 2 destinations; fixture.
        $json = (string) json_encode([
            'odLines' => [
                'features' => [
                    ['attributes' => ['OriginOID' => 1, 'DestinationOID' => 1, 'Total_Time' => 5,  'Total_Distance' => 2]],
                    ['attributes' => ['OriginOID' => 1, 'DestinationOID' => 2, 'Total_Time' => 10, 'Total_Distance' => 5]],
                    ['attributes' => ['OriginOID' => 2, 'DestinationOID' => 1, 'Total_Time' => 8,  'Total_Distance' => 4]],
                    ['attributes' => ['OriginOID' => 2, 'DestinationOID' => 2, 'Total_Time' => 3,  'Total_Distance' => 1]],
                ],
            ],
        ]);

        $connector = self::makeConnector(self::respondingClient(new Response(
            200,
            ['Content-Type' => 'application/json'],
            $json,
        )));

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(40.7128, -74.006), new LatLng(40.758, -73.9855)],
            destinations: [new LatLng(40.7484, -73.9856), new LatLng(40.7614, -73.9776)],
        ));

        self::assertCount(4, $result->cells);

        // OIDs are 1-based on the wire → decrement to 0-based.
        self::assertSame(0, $result->cells[0]->originIndex);
        self::assertSame(0, $result->cells[0]->destinationIndex);
        // 5 min × 60 = 300s.
        self::assertSame(300.0, $result->cells[0]->durationSeconds);
        // 2 miles × 1609.344 = 3218.688 m.
        self::assertEqualsWithDelta(3218.688, $result->cells[0]->distanceMeters, 0.001);

        // (1, 1) — fourth cell.
        self::assertSame(1, $result->cells[3]->originIndex);
        self::assertSame(1, $result->cells[3]->destinationIndex);
        self::assertSame(180.0, $result->cells[3]->durationSeconds);
        self::assertEqualsWithDelta(1609.344, $result->cells[3]->distanceMeters, 0.001);

        self::assertIsArray($result->raw);
    }

    #[Test]
    public function matrixPostsFormEncodedOdMatrixWithTokenAndPipeJoinedTriplets(): void
    {
        $recorder = self::recordingClient(self::okMatrixResponse());
        $connector = self::makeConnector($recorder, new EsriConfig(apiKey: 'esri-test-token'));

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(40.7128, -74.006), new LatLng(40.758, -73.9855)],
            destinations: [new LatLng(40.7484, -73.9856), new LatLng(40.7614, -73.9776)],
        ));

        self::assertNotNull($recorder->captured);
        self::assertSame('POST', $recorder->captured->getMethod());
        self::assertStringContainsString('solveODCostMatrix', (string) $recorder->captured->getUri());
        self::assertStringContainsString(
            'application/x-www-form-urlencoded',
            $recorder->captured->getHeaderLine('Content-Type'),
        );

        $form = [];
        parse_str((string) $recorder->captured->getBody(), $form);

        self::assertSame('esri-test-token', $form['token']);
        self::assertSame('json', $form['f']);
        self::assertSame('esriNAODOutputStraightLine', $form['outputType']);

        // Pipe-joined `lng,lat,id` triplets, 1-based.
        self::assertSame('-74.006,40.7128,1;-73.9855,40.758,2', $form['origins']);
        self::assertSame('-73.9856,40.7484,1;-73.9776,40.7614,2', $form['destinations']);
    }

    #[Test]
    public function matrixForwardsArcgisTokenWhenConfiguredWithArcgisAuth(): void
    {
        $recorder = self::recordingClient(self::okMatrixResponse());
        $connector = self::makeConnector($recorder, new EsriConfig(arcgisToken: 'arcgis-oauth-token'));

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
        ));

        self::assertNotNull($recorder->captured);
        $form = [];
        parse_str((string) $recorder->captured->getBody(), $form);
        self::assertSame('arcgis-oauth-token', $form['token']);
    }

    #[Test]
    public function matrixAppliesAvoidTollsRestrictionWhenRequested(): void
    {
        $recorder = self::recordingClient(self::okMatrixResponse());
        $connector = self::makeConnector($recorder);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
            avoidTolls: true,
        ));

        self::assertNotNull($recorder->captured);
        $form = [];
        parse_str((string) $recorder->captured->getBody(), $form);
        self::assertSame('Avoid Toll Roads', $form['restrictionAttributeNames']);
    }

    #[Test]
    public function matrixMergesPassthroughBodyHeadersAndQuery(): void
    {
        $recorder = self::recordingClient(self::okMatrixResponse());
        $connector = self::makeConnector($recorder);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
            passthrough: new Passthrough(
                body: ['extraParam' => 'extraValue'],
                headers: ['X-Custom' => 'custom'],
                query: ['returnExtra' => 'true'],
            ),
        ));

        self::assertNotNull($recorder->captured);
        $form = [];
        parse_str((string) $recorder->captured->getBody(), $form);
        self::assertSame('extraValue', $form['extraParam']);
        self::assertSame('custom', $recorder->captured->getHeaderLine('X-Custom'));
        self::assertStringContainsString('returnExtra=true', (string) $recorder->captured->getUri());
    }

    #[Test]
    public function matrixThrowsConnectorErrorOnEsriErrorBodyAt200(): void
    {
        // ESRI's signature 200-with-error-body case.
        $json = (string) json_encode([
            'error' => ['message' => 'Token expired', 'code' => 498],
            'odLines' => ['features' => []],
        ]);

        $connector = self::makeConnector(self::respondingClient(new Response(
            200,
            ['Content-Type' => 'application/json'],
            $json,
        )));

        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
            ));
            self::fail('Expected ConnectorError');
        } catch (ConnectorError $error) {
            self::assertSame(200, $error->statusCode);
            self::assertSame(ProviderCode::AuthFailed, $error->providerCode);
            self::assertSame('Token expired', $error->providerMessage);
        }
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>|null, ProviderCode}>
     */
    public static function mapVendorErrorCases(): iterable
    {
        yield '401 → AuthFailed'           => [401, null, ProviderCode::AuthFailed];
        yield '403 → AuthFailed'           => [403, null, ProviderCode::AuthFailed];
        yield '429 → RateLimited'          => [429, null, ProviderCode::RateLimited];
        yield '400 → InvalidRequest'       => [400, null, ProviderCode::InvalidRequest];
        yield '500 → ProviderUnavailable'  => [500, null, ProviderCode::ProviderUnavailable];
        yield '503 → ProviderUnavailable'  => [503, null, ProviderCode::ProviderUnavailable];
        yield '418 → Unknown'              => [418, null, ProviderCode::Unknown];
        yield 'body 498 → AuthFailed'      => [200, ['error' => ['code' => 498, 'message' => 'Invalid token']], ProviderCode::AuthFailed];
        yield 'body 400 → InvalidRequest'  => [200, ['error' => ['code' => 400, 'message' => 'Bad query']], ProviderCode::InvalidRequest];
        yield 'body 500 → ProviderUnav'    => [200, ['error' => ['code' => 500, 'message' => 'Internal']], ProviderCode::ProviderUnavailable];
        // Esri 429-precedence: HTTP 429 wins over an ambiguous in-body code
        // that would otherwise fall through to Unknown.
        yield 'http 429 + body 12345 → RateLimited' => [429, ['error' => ['code' => 12345, 'message' => 'Too Many Requests']], ProviderCode::RateLimited];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('mapVendorErrorCases')]
    public function matrixMapsVendorErrorsToProviderCode(int $status, ?array $body, ProviderCode $expected): void
    {
        $payload = $body === null ? '' : (string) json_encode($body);
        $connector = self::makeConnector(self::respondingClient(new Response($status, [], $payload)));

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
        $vendor = ['error' => ['code' => 429, 'message' => 'rate limited']];
        $connector = self::makeConnector(self::respondingClient(new Response(
            429,
            ['Retry-After' => '60', 'Content-Type' => 'application/json'],
            (string) json_encode($vendor),
        )));

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
            self::assertStringContainsString('retry after 60 seconds', $error->providerMessage);
            self::assertStringContainsString('rate limited', $error->providerMessage);

            self::assertIsArray($error->cause);
            self::assertSame('60', $error->cause['retryAfter']);
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

    private static function makeConnector(ClientInterface $client, ?EsriConfig $config = null): EsriMatrixConnector
    {
        $factory = new HttpFactory();

        return new EsriMatrixConnector(
            $config ?? new EsriConfig(apiKey: 'test-api-key'),
            $client,
            $factory,
            $factory,
        );
    }

    private static function okMatrixResponse(): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode([
                'odLines' => [
                    'features' => [
                        ['attributes' => ['OriginOID' => 1, 'DestinationOID' => 1, 'Total_Time' => 1, 'Total_Distance' => 1]],
                    ],
                ],
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
