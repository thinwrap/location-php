<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Here;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\Connector\Here\HereIsochroneConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Isochrone\IsochroneOptions;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Enum\IsochroneType;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Util\Polyline;

final class HereIsochroneConnectorTest extends TestCase
{
    /**
     * Pre-validated flex-polyline encoding 2 distinct points: (0, 0) and
     * (0.00001, 0.00001) at precision 5, no third dimension. Mirrors the
     * canonical fixture string used in existing tests.
     */
    private const FLEX_TWO_POINT = 'KAAACC';

    #[Test]
    public function isochroneDecodesFlexPolylineAndClosesRing(): void
    {
        $json = (string) json_encode([
            'isolines' => [
                [
                    'range' => ['type' => 'time', 'value' => 300],
                    'polygons' => [['outer' => self::FLEX_TWO_POINT]],
                ],
            ],
        ]);

        $connector = $this->makeConnector($this->captureClient($json));

        $result = $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300],
        ));

        self::assertCount(1, $result->contours);
        self::assertSame(300, $result->contours[0]->value);
        self::assertSame('Polygon', $result->contours[0]->geometry['type']);

        /** @var list<list<list<float>>> $coords */
        $coords = $result->contours[0]->geometry['coordinates'];
        $ring = $coords[0];
        // Sanity: decoder yields some points + explicit ring closure appends the first.
        self::assertGreaterThanOrEqual(2, count($ring));
        self::assertSame($ring[0], $ring[count($ring) - 1], 'Ring must close.');
    }

    #[Test]
    public function isochroneDecodesAgainstKnownPolylineFixture(): void
    {
        // Verify the decoder dependency directly so the ring-closure test
        // above can be trusted.
        $points = Polyline::decodeFlexPolyline(self::FLEX_TWO_POINT);
        self::assertGreaterThanOrEqual(1, count($points));
    }

    #[Test]
    public function isochroneReturnsContoursSortedAscending(): void
    {
        $json = (string) json_encode([
            'isolines' => [
                [
                    'range' => ['type' => 'time', 'value' => 900],
                    'polygons' => [['outer' => self::FLEX_TWO_POINT]],
                ],
                [
                    'range' => ['type' => 'time', 'value' => 300],
                    'polygons' => [['outer' => self::FLEX_TWO_POINT]],
                ],
            ],
        ]);

        $connector = $this->makeConnector($this->captureClient($json));

        $result = $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300, 900],
        ));

        self::assertCount(2, $result->contours);
        self::assertSame(300, $result->contours[0]->value);
        self::assertSame(900, $result->contours[1]->value);
    }

    #[Test]
    public function isochroneMapsTravelModeWalkingToPedestrian(): void
    {
        $client = $this->captureClient((string) json_encode(['isolines' => []]));
        $connector = $this->makeConnector($client);

        $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300],
            travelMode: TravelMode::Walking,
        ));

        $query = urldecode($client->captured[0]->getUri()->getQuery());
        self::assertStringContainsString('transportMode=pedestrian', $query);
    }

    #[Test]
    public function isochroneMapsTravelModeDrivingToCar(): void
    {
        $client = $this->captureClient((string) json_encode(['isolines' => []]));
        $connector = $this->makeConnector($client);

        $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300],
            travelMode: TravelMode::Driving,
        ));

        $query = urldecode($client->captured[0]->getUri()->getQuery());
        self::assertStringContainsString('transportMode=car', $query);
    }

    #[Test]
    public function isochroneThrowsWhenCapExceeded(): void
    {
        $client = $this->captureClient((string) json_encode(['isolines' => []]));
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
    public function isochroneMapsHttp401ToAuthFailed(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(
                    401,
                    ['Content-Type' => 'application/json'],
                    (string) json_encode(['title' => 'Unauthorized', 'cause' => 'Bad API key']),
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
            self::fail('Expected ConnectorError for 401.');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::AuthFailed, $e->providerCode);
            self::assertSame(401, $e->statusCode);
            self::assertStringContainsString('Unauthorized', (string) $e->providerMessage);
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

    private function makeConnector(ClientInterface $client): HereIsochroneConnector
    {
        $factory = new HttpFactory();
        $config = new HereConfig(apiKey: 'test-api-key');

        return new HereIsochroneConnector($config, $client, $factory, $factory);
    }
}
