<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Facade;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\EsriConfig;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Config\TomTomConfig;
use Thinwrap\Location\Connector\Esri\EsriIsochroneConnector;
use Thinwrap\Location\Connector\Here\HereIsochroneConnector;
use Thinwrap\Location\Connector\Mapbox\MapboxIsochroneConnector;
use Thinwrap\Location\Connector\TomTom\TomTomIsochroneConnector;
use Thinwrap\Location\DTO\Isochrone\IsochroneOptions;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Enum\IsochroneType;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Isochrone;

/**
 * Facade delegation + wiring tests for the {@see Isochrone} facade.
 *
 * Mirrors the structure of {@see RoutingTest}, {@see MatrixTest} and
 * {@see GeocodingTest}: a per-provider matrix that proves each isochrone-capable
 * provider id constructs and wires the expected concrete connector class, a BYO
 * PSR-18 client seam test through the public third constructor argument, and a
 * per-arm delegation test. The isochrone union excludes Google + OSRM
 * (baseline coverage).
 */
final class IsochroneTest extends TestCase
{
    #[Test]
    public function itDelegatesToMapboxIsochroneConnector(): void
    {
        $json = json_encode([
            'type' => 'FeatureCollection',
            'features' => [[
                'properties' => ['contour' => 5, 'metric' => 'time'],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[34.78, 32.08], [34.79, 32.09], [34.78, 32.08]]],
                ],
            ]],
        ]);

        $factory = new HttpFactory();
        $mockClient = $this->createMockClient($json);

        // BYO the mock client through the public 3rd constructor arg; PHP 8.4
        // forbids overwriting the readonly `httpClient` via reflection.
        $isochrone = new Isochrone(LocationProviderId::Mapbox, new MapboxConfig(accessToken: 'test-token'), $mockClient);
        $this->injectFactoriesOnly($isochrone, $factory);

        $result = $isochrone->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300],
        ));

        self::assertCount(1, $result->contours);
        // response contour minutes are converted back to
        // seconds so the contour `value` matches the caller-supplied input
        // unit. Mapbox `contour: 5` (minutes) → 300 (seconds).
        self::assertSame(300, (int) $result->contours[0]->value);
        self::assertSame('Polygon', $result->contours[0]->geometry['type']);
    }

    /**
     * Per-arm wiring + provider-id matrix (C2-BH-4 / C2-AA-6 / C2-AA-7).
     *
     * Proves that every isochrone-supported provider id constructs the facade
     * AND that the private `connector` property is the expected concrete class
     * (a copy-paste-wrong `match` arm would otherwise pass the delegation tests
     * because every connector returns the same `IsochroneResult` shape). Also
     * asserts the facade's `getProviderId` for each supported provider.
     *
     * @return iterable<string, array{LocationProviderId, MapboxConfig|HereConfig|EsriConfig|TomTomConfig, class-string, string}>
     */
    public static function supportedProviderProvider(): iterable
    {
        yield 'mapbox' => [LocationProviderId::Mapbox, new MapboxConfig(accessToken: 't'), MapboxIsochroneConnector::class, 'mapbox'];
        yield 'here' => [LocationProviderId::Here, new HereConfig(apiKey: 'k'), HereIsochroneConnector::class, 'here'];
        yield 'esri' => [LocationProviderId::Esri, new EsriConfig(apiKey: 'k'), EsriIsochroneConnector::class, 'esri'];
        yield 'tomtom' => [LocationProviderId::TomTom, new TomTomConfig(apiKey: 'k'), TomTomIsochroneConnector::class, 'tomtom'];
    }

    /**
     * @param MapboxConfig|HereConfig|EsriConfig|TomTomConfig $config
     * @param class-string $expectedConnectorClass
     */
    #[Test]
    #[DataProvider('supportedProviderProvider')]
    public function itWiresTheExpectedConnectorForEverySupportedProvider(
        LocationProviderId $providerId,
        MapboxConfig|HereConfig|EsriConfig|TomTomConfig $config,
        string $expectedConnectorClass,
        string $expectedProviderId,
    ): void {
        $isochrone = new Isochrone($providerId, $config);

        self::assertSame($expectedProviderId, $isochrone->getProviderId());
        self::assertSame($providerId, $isochrone->providerId);

        $connector = $this->readConnector($isochrone);
        self::assertInstanceOf($expectedConnectorClass, $connector);
        // The connector's OWN provider id must agree — guards a mis-wired arm
        // that `getProviderId` (which reads the facade's own field) cannot see.
        self::assertSame($expectedProviderId, $connector->getProviderId());
    }

    /**
     * BYO PSR-18 client seam through the PUBLIC third constructor argument
     * (C2-BH-5 / C2-EC-5). The facade's `connector` property is readonly and
     * PHP 8.4 forbids overwriting it via reflection, so the BYO client is proven
     * by passing the spy straight into the constructor and asserting its
     * `sendRequest` is invoked end-to-end through `isochrone`.
     *
     * Only the PSR-17 request/stream factories are reflection-injected: those
     * are NOT BYO-able through the facade (C2-EC-4, a non-test concern) so they
     * would otherwise fall back to discovery. The CLIENT — the actual seam under
     * test — comes exclusively through the public constructor path.
     */
    #[Test]
    public function itForwardsAByoClientPassedToTheConstructor(): void
    {
        $json = json_encode([
            'type' => 'FeatureCollection',
            'features' => [[
                'properties' => ['contour' => 5, 'metric' => 'time'],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[34.78, 32.08], [34.79, 32.09], [34.78, 32.08]]],
                ],
            ]],
        ]);

        $spy = new class ($json) implements ClientInterface {
            public int $calls = 0;

            public ?RequestInterface $lastRequest = null;

            public function __construct(private readonly string $json) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->calls;
                $this->lastRequest = $request;

                return new Response(200, ['Content-Type' => 'application/json'], $this->json);
            }
        };

        // Spy passed as the 3rd constructor argument — the BYO seam under test.
        $isochrone = new Isochrone(
            LocationProviderId::Mapbox,
            new MapboxConfig(accessToken: 'test-token'),
            $spy,
        );

        // Inject ONLY the PSR-17 factories (not the client) so the request can
        // be built without relying on ambient discovery.
        $this->injectFactoriesOnly($isochrone, new HttpFactory());

        $result = $isochrone->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300],
        ));

        self::assertSame(1, $spy->calls, 'BYO client passed to the constructor must be the one used to dispatch');
        self::assertNotNull($spy->lastRequest);
        self::assertCount(1, $result->contours);
    }

    #[Test]
    public function itThrowsForUnsupportedProvider(): void
    {
        // Google/OSRM are excluded from the isochrone config union, so
        // passing GoogleConfig rejects the pairing with a native \TypeError at the
        // parameter boundary BEFORE the constructor body runs.: facade
        // construction misuse is a programmer error (native \Error subtypes:
        // \TypeError here at the boundary, \LogicException for an in-union config
        // with Google/OSRM), NOT a ConnectorError — parity with the TS plain Error.
        $this->expectException(\TypeError::class);
        new Isochrone(LocationProviderId::Google, new \Thinwrap\Location\Config\GoogleConfig(apiKey: 'key')); // @phpstan-ignore-line argument.type
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

    private function readConnector(Isochrone $isochrone): object
    {
        $ref = new \ReflectionObject($isochrone);
        $connector = $ref->getProperty('connector')->getValue($isochrone);
        self::assertIsObject($connector);

        return $connector;
    }

    private function injectFactoriesOnly(Isochrone $isochrone, HttpFactory $factory): void
    {
        $connector = $this->readConnector($isochrone);
        $connectorRef = new \ReflectionObject($connector);
        $parentRef = $connectorRef->getParentClass();
        if ($parentRef === false) {
            throw new \LogicException('Expected connector to extend BaseConnector');
        }

        // Inject only when not already initialized. The connector eagerly
        // auto-discovers PSR-17 factories (guzzle is installed), so on PHP 8.4 —
        // where overwriting an initialized readonly property via reflection is
        // forbidden — this is a no-op and discovery is used. The BYO client is
        // supplied through the public constructor arg (the seam under test).
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
