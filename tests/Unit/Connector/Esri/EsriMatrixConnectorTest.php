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
use Thinwrap\Location\Enum\TravelMode;

final class EsriMatrixConnectorTest extends TestCase
{
    #[Test]
    public function getProviderIdReturnsEsri(): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(200, [], '{}')));

        self::assertSame('esri', $connector->getProviderId());
    }

    #[Test]
    public function matrixReturnsNormalizedCellsFromSparseMatrix(): void
    {
        // Real esriNAODOutputSparseMatrix shape (World OD Cost Matrix, verified
        // live 2026-07-20): odCostMatrix maps 1-based origin OID → { destOID:
        // [values in costAttributeNames order] }. TravelTime is minutes,
        // Kilometers is km. 2 origins × 1 destination.
        $json = (string) json_encode([
            'requestID' => 'req-1',
            'odCostMatrix' => [
                'costAttributeNames' => ['TravelTime', 'Kilometers'],
                '1' => ['1' => [93.25787017375364, 98.94833503121721]],
                '2' => ['1' => [81.54786997057796, 99.08865887338234]],
            ],
            'messages' => [],
        ]);

        $connector = self::makeConnector(self::respondingClient(new Response(
            200,
            ['Content-Type' => 'application/json'],
            $json,
        )));

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(40.7484, -73.9857), new LatLng(40.758, -73.9855)],
            destinations: [new LatLng(41.1792, -73.1952)],
        ));

        self::assertCount(2, $result->cells);

        // OIDs are 1-based on the wire → decrement to 0-based.
        self::assertSame(0, $result->cells[0]->originIndex);
        self::assertSame(0, $result->cells[0]->destinationIndex);
        // TravelTime 93.25787 min × 60 = 5595.472 s.
        self::assertEqualsWithDelta(93.25787017375364 * 60, $result->cells[0]->durationSeconds, 1e-6);
        // Kilometers 98.94834 km × 1000 = 98948.335 m.
        self::assertEqualsWithDelta(98.94833503121721 * 1000, $result->cells[0]->distanceMeters, 1e-6);

        // Second origin → same destination.
        self::assertSame(1, $result->cells[1]->originIndex);
        self::assertSame(0, $result->cells[1]->destinationIndex);
        self::assertEqualsWithDelta(81.54786997057796 * 60, $result->cells[1]->durationSeconds, 1e-6);
        self::assertEqualsWithDelta(99.08865887338234 * 1000, $result->cells[1]->distanceMeters, 1e-6);

        self::assertIsArray($result->raw);
    }

    #[Test]
    public function matrixReturnsNormalizedCellsFromOdLinesFallback(): void
    {
        // Real esriNAODOutputStraightLines fallback shape: odLines.features[]
        // with 1-based OriginID/DestinationID + Total_TravelTime (minutes) /
        // Total_Kilometers (km).
        $json = (string) json_encode([
            'odLines' => [
                'features' => [
                    ['attributes' => [
                        'ObjectID' => 1,
                        'OriginID' => 1,
                        'DestinationID' => 1,
                        'DestinationRank' => 1,
                        'Total_TravelTime' => 93.25787017375364,
                        'Total_Kilometers' => 98.94833503121721,
                        'Shape_Length' => 0.90,
                    ]],
                ],
            ],
        ]);

        $connector = self::makeConnector(self::respondingClient(new Response(
            200,
            ['Content-Type' => 'application/json'],
            $json,
        )));

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(40.7484, -73.9857)],
            destinations: [new LatLng(41.1792, -73.1952)],
        ));

        self::assertCount(1, $result->cells);
        self::assertSame(0, $result->cells[0]->originIndex);
        self::assertSame(0, $result->cells[0]->destinationIndex);
        self::assertEqualsWithDelta(93.25787017375364 * 60, $result->cells[0]->durationSeconds, 1e-6);
        self::assertEqualsWithDelta(98.94833503121721 * 1000, $result->cells[0]->distanceMeters, 1e-6);
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
        self::assertSame('esriNAODOutputSparseMatrix', $form['outputType']);
        self::assertSame('TravelTime', $form['impedanceAttributeName']);
        self::assertSame('Kilometers', $form['accumulateAttributeNames']);

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
    public function matrixDecodesWalkTimeImpedanceColumnForWalking(): void
    {
        // With a WALK travel mode, ArcGIS overrides the requested impedance, so
        // costAttributeNames comes back as ['WalkTime', 'Kilometers'] — NOT
        // 'TravelTime'. The pre-fix decoder looked up 'TravelTime' only and
        // silently reported every duration as 0. Live values from
        // route-api.arcgis.com (2026-07-21).
        $walkMin = 13.094903051108146;
        $walkKm = 1.091226960340165;
        $json = (string) json_encode([
            'odCostMatrix' => [
                'costAttributeNames' => ['WalkTime', 'Kilometers'],
                '1' => ['1' => [$walkMin, $walkKm]],
            ],
        ]);

        $connector = self::makeConnector(self::respondingClient(new Response(
            200,
            ['Content-Type' => 'application/json'],
            $json,
        )));

        $result = $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
            travelMode: TravelMode::Walking,
        ));

        self::assertCount(1, $result->cells);
        self::assertEqualsWithDelta($walkMin * 60, $result->cells[0]->durationSeconds, 1e-6);
        self::assertEqualsWithDelta($walkKm * 1000, $result->cells[0]->distanceMeters, 1e-6);
    }

    #[Test]
    public function matrixSendsFullWalkingTravelModeObject(): void
    {
        $recorder = self::recordingClient(self::okMatrixResponse());
        $connector = self::makeConnector($recorder);

        $connector->matrix(new MatrixOptions(
            origins: [new LatLng(0, 0)],
            destinations: [new LatLng(1, 1)],
            travelMode: TravelMode::Walking,
        ));

        self::assertNotNull($recorder->captured);
        $form = [];
        parse_str((string) $recorder->captured->getBody(), $form);
        self::assertIsString($form['travelMode'] ?? null);
        /** @var array<string,mixed> $travelMode */
        $travelMode = json_decode((string) $form['travelMode'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('WALK', $travelMode['type']);
        self::assertSame('WalkTime', $travelMode['impedanceAttributeName']);
    }

    #[Test]
    public function matrixRejectsCyclingWithUnsupportedTravelMode(): void
    {
        $connector = self::makeConnector(self::respondingClient(new Response(200, [], '{}')));

        try {
            $connector->matrix(new MatrixOptions(
                origins: [new LatLng(0, 0)],
                destinations: [new LatLng(1, 1)],
                travelMode: TravelMode::Cycling,
            ));
            self::fail('Expected ConnectorError.');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::UnsupportedTravelMode, $e->providerCode);
        }
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
                'odCostMatrix' => [
                    'costAttributeNames' => ['TravelTime', 'Kilometers'],
                    '1' => ['1' => [1.0, 1.0]],
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
