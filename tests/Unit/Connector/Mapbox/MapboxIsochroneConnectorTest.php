<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Mapbox;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Connector\Mapbox\MapboxIsochroneConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Isochrone\IsochroneOptions;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\Enum\IsochroneType;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;

final class MapboxIsochroneConnectorTest extends TestCase
{
    #[Test]
    public function isochroneReturnsSortedContoursWithSecondsValue(): void
    {
        $json = (string) json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'properties' => ['contour' => 10, 'metric' => 'time'],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[34.78, 32.08], [34.79, 32.09], [34.78, 32.08]]],
                    ],
                ],
                [
                    'properties' => ['contour' => 5, 'metric' => 'time'],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[34.78, 32.08], [34.79, 32.09], [34.78, 32.08]]],
                    ],
                ],
            ],
        ]);

        $client = $this->captureClient($json);
        $connector = $this->makeConnector($client);

        $result = $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300, 600],
        ));

        // sorted ascending;/6: minutes → seconds round-trip.
        self::assertCount(2, $result->contours);
        self::assertSame(300, (int) $result->contours[0]->value);
        self::assertSame(600, (int) $result->contours[1]->value);
        self::assertSame('Polygon', $result->contours[0]->geometry['type']);
    }

    #[Test]
    public function isochroneAppliesPolygonsTrueInvariantEvenWhenPassthroughTriesToOverride(): void
    {
        $client = $this->captureClient((string) json_encode(['features' => []]));
        $connector = $this->makeConnector($client);

        $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300],
            passthrough: new Passthrough(query: ['polygons' => 'false']),
        ));

        // polygons=true wins over passthrough override.
        $query = $client->captured[0]->getUri()->getQuery();
        self::assertStringContainsString('polygons=true', $query);
        self::assertStringNotContainsString('polygons=false', $query);
    }

    #[Test]
    public function isochroneRejectsCyclingAtTheBaseDto(): void
    {
        /// (PINNED): `cycling` is rejected at the IsochroneOptions DTO with
        // `unsupported_travel_mode` (only driving/walking on the unified facade —
        // HERE/Esri lack cycling). The constructor throws before the connector is
        // ever reached. Mapbox's native cycling profile is still reachable via
        // `_passthrough`. (Previously this test asserted cycling reached the wire.)
        $this->expectException(ConnectorError::class);
        $this->expectExceptionMessageMatches('/cycling/i');

        new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300],
            travelMode: TravelMode::Cycling,
        );
    }

    #[Test]
    public function isochroneConvertsTimeSecondsToContourMinutes(): void
    {
        $client = $this->captureClient((string) json_encode(['features' => []]));
        $connector = $this->makeConnector($client);

        $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300, 900],
        ));

        // 300 sec → 5 min, 900 sec → 15 min.
        $query = urldecode($client->captured[0]->getUri()->getQuery());
        self::assertStringContainsString('contours_minutes=5,15', $query);
    }

    #[Test]
    public function isochronePassesDistanceMetersThrough(): void
    {
        $client = $this->captureClient((string) json_encode(['features' => []]));
        $connector = $this->makeConnector($client);

        $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Distance,
            values: [1000, 5000],
        ));

        $query = urldecode($client->captured[0]->getUri()->getQuery());
        self::assertStringContainsString('contours_meters=1000,5000', $query);
    }

    #[Test]
    public function isochroneThrowsWhenCapExceeded(): void
    {
        $client = $this->captureClient((string) json_encode(['features' => []]));
        $connector = $this->makeConnector($client);

        try {
            $connector->isochrone(new IsochroneOptions(
                center: new LatLng(32.08, 34.78),
                type: IsochroneType::Time,
                values: [60, 120, 180, 240, 300],
            ));
            self::fail('Expected ConnectorError for cap > 4.');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertSame([], $client->captured, 'No HTTP request should be made when cap is exceeded.');
        }
    }

    #[Test]
    public function isochroneMapsHttpErrorsToProviderCode(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(
                    429,
                    ['Retry-After' => '42', 'Content-Type' => 'application/json'],
                    (string) json_encode(['message' => 'Too many requests']),
                );
            }
        };
        $connector = $this->makeConnector($client);

        try {
            $connector->isochrone(new IsochroneOptions(
                center: new LatLng(32.08, 34.78),
                type: IsochroneType::Time,
                values: [300],
            ));
            self::fail('Expected ConnectorError for 429.');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
            self::assertSame(429, $e->statusCode);
            self::assertStringContainsString('retry after 42 seconds', (string) $e->providerMessage);
            self::assertIsArray($e->cause);
            /** @var array{retryAfter?: mixed} $cause */
            $cause = $e->cause;
            self::assertSame('42', $cause['retryAfter'] ?? null);
        }
    }

    /**
     * @return ClientInterface&object{captured: list<RequestInterface>}
     */
    private function captureClient(string $json): ClientInterface
    {
        return new class ($json) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];

            public function __construct(private readonly string $json) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                return new Response(200, ['Content-Type' => 'application/json'], $this->json);
            }
        };
    }

    private function makeConnector(ClientInterface $client): MapboxIsochroneConnector
    {
        $factory = new HttpFactory();
        $config = new MapboxConfig(accessToken: 'test-token');

        return new MapboxIsochroneConnector($config, $client, $factory, $factory);
    }
}
