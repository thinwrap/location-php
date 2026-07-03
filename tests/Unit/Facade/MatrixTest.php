<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Facade;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Config\OsrmConfig;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Matrix;

/**
 * Facade delegation tests for the {@see Matrix} facade.
 *
 * Mirrors the structure of {@see RoutingTest}: per-provider fixtures injected
 * into the per-arm connector via reflection on the BaseConnector PSR-18/PSR-17
 * properties. The facade itself only contracts the dispatch — the wire-level
 * normalization is exercised by per-connector tests.
 */
final class MatrixTest extends TestCase
{
    #[Test]
    public function itDelegatesToGoogleMatrixConnector(): void
    {
        $json = (string) json_encode([
            ['originIndex' => 0, 'destinationIndex' => 0, 'distanceMeters' => 5000, 'duration' => '300s', 'staticDuration' => '300s', 'condition' => 'ROUTE_EXISTS'],
        ]);

        $factory = new HttpFactory();
        $mockClient = $this->createMockClient($json);

        // BYO the mock client through the public 3rd constructor arg; PHP 8.4
        // forbids overwriting the readonly `httpClient` via reflection.
        $matrix = new Matrix(LocationProviderId::Google, new GoogleConfig(apiKey: 'test-key'), $mockClient);
        $this->injectFactoriesOnly($matrix, $factory);

        $result = $matrix->matrix(new MatrixOptions(
            origins: [new LatLng(32.08, 34.78)],
            destinations: [new LatLng(32.11, 34.85)],
        ));

        self::assertSame('google', $matrix->getProviderId());
        self::assertCount(1, $result->cells);
        self::assertSame(0, $result->cells[0]->originIndex);
        self::assertSame(0, $result->cells[0]->destinationIndex);
        self::assertSame(5000.0, $result->cells[0]->distanceMeters);
        self::assertSame(300.0, $result->cells[0]->durationSeconds);
    }

    #[Test]
    public function itExposesTheProviderIdForEveryProvider(): void
    {
        $cases = [
            [LocationProviderId::Google, new GoogleConfig(apiKey: 'k'), 'google'],
            [LocationProviderId::Mapbox, new MapboxConfig(accessToken: 't'), 'mapbox'],
            [LocationProviderId::Osrm, new OsrmConfig(baseUrl: 'https://router.project-osrm.org'), 'osrm'],
        ];

        foreach ($cases as [$providerId, $config, $expected]) {
            $matrix = new Matrix($providerId, $config);
            self::assertSame($expected, $matrix->getProviderId());
            self::assertSame($providerId, $matrix->providerId);
        }
    }

    private function createMockClient(string $json): ClientInterface
    {
        return new class ($json) implements ClientInterface {
            public function __construct(private readonly string $json) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], $this->json);
            }
        };
    }

    /**
     * Inject the PSR-17 factories via reflection ONLY when not already
     * initialized. The connector eagerly auto-discovers PSR-17 factories
     * (guzzle is installed), so on PHP 8.4 this is a no-op and discovery is used.
     * The BYO client is supplied through the public constructor arg.
     */
    private function injectFactoriesOnly(Matrix $matrix, HttpFactory $factory): void
    {
        $ref = new \ReflectionObject($matrix);
        $connector = $ref->getProperty('connector')->getValue($matrix);
        self::assertIsObject($connector);

        $connectorRef = new \ReflectionObject($connector);
        $parentRef = $connectorRef->getParentClass();
        if ($parentRef === false) {
            throw new \LogicException('Expected connector to extend BaseConnector');
        }

        $reqProp = $parentRef->getProperty('requestFactory');
        if (!$reqProp->isInitialized($connector)) {
            $reqProp->setValue($connector, $factory);
        }
        $streamProp = $parentRef->getProperty('streamFactory');
        if (!$streamProp->isInitialized($connector)) {
            $streamProp->setValue($connector, $factory);
        }
    }
}
