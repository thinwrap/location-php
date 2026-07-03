<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Esri;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\EsriConfig;
use Thinwrap\Location\Connector\Esri\EsriIsochroneConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Isochrone\IsochroneOptions;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Enum\IsochroneType;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;

final class EsriIsochroneConnectorTest extends TestCase
{
    #[Test]
    public function isochroneConvertsTimeBreaksToMinutesAndPostsForm(): void
    {
        $client = $this->captureClient((string) json_encode([
            'saPolygons' => ['features' => []],
        ]));
        $connector = $this->makeConnector($client);

        $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300, 900],
        ));

        // form-encoded POST.
        $request = $client->captured[0];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $body = (string) $request->getBody();
        // 300s→5min, 900s→15min.
        self::assertStringContainsString('defaultBreaks=5%2C15', $body);
        // units.
        self::assertStringContainsString('breakUnits=esriDriveTimeUnitsMinutes', $body);
        // facilities is JSON-encoded FeatureSet.
        self::assertStringContainsString('facilities=', $body);
        self::assertStringContainsString('outSR=4326', $body);
    }

    #[Test]
    public function isochroneConvertsDistanceBreaksToMeters(): void
    {
        $client = $this->captureClient((string) json_encode([
            'saPolygons' => ['features' => []],
        ]));
        $connector = $this->makeConnector($client);

        $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Distance,
            values: [1000, 5000],
        ));

        $body = (string) $client->captured[0]->getBody();
        self::assertStringContainsString('defaultBreaks=1000%2C5000', $body);
        self::assertStringContainsString('breakUnits=esriDriveDistanceUnitsMeters', $body);
    }

    #[Test]
    public function isochroneNormalizesPolygonAndConvertsToBreakBackToSeconds(): void
    {
        $client = $this->captureClient((string) json_encode([
            'saPolygons' => [
                'features' => [
                    [
                        'attributes' => ['FromBreak' => 0, 'ToBreak' => 5],
                        'geometry' => [
                            'rings' => [
                                [[34.78, 32.08], [34.79, 32.09], [34.78, 32.08]],
                            ],
                        ],
                    ],
                ],
            ],
        ]));
        $connector = $this->makeConnector($client);

        $result = $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300],
        ));

        self::assertCount(1, $result->contours);
        // ToBreak (5 min) → 300 sec round-trip.
        self::assertSame(300, (int) $result->contours[0]->value);
        self::assertSame('Polygon', $result->contours[0]->geometry['type']);
        self::assertCount(1, $result->contours[0]->geometry['coordinates']);
    }

    #[Test]
    public function isochroneRaisesOn200WithErrorBody(): void
    {
        $client = $this->captureClient((string) json_encode([
            'error' => ['code' => 400, 'message' => 'Invalid token'],
        ]));
        $connector = $this->makeConnector($client);

        try {
            $connector->isochrone(new IsochroneOptions(
                center: new LatLng(32.08, 34.78),
                type: IsochroneType::Time,
                values: [300],
            ));
            self::fail('Expected ConnectorError for 200-with-error-body.');
        } catch (ConnectorError $e) {
            // 200-with-error-body inspection.
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertStringContainsString('Invalid token', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function isochroneMaps498BodyErrorToAuthFailed(): void
    {
        $client = $this->captureClient((string) json_encode([
            'error' => ['code' => 498, 'message' => 'Invalid token'],
        ]));
        $connector = $this->makeConnector($client);

        try {
            $connector->isochrone(new IsochroneOptions(
                center: new LatLng(32.08, 34.78),
                type: IsochroneType::Time,
                values: [300],
            ));
            self::fail('Expected ConnectorError for body error 498.');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::AuthFailed, $e->providerCode);
        }
    }

    #[Test]
    public function isochroneHttp429WithGenericBodyCodeMapsToRateLimited(): void
    {
        // Esri 429-precedence regression: a genuine HTTP 429 must classify as
        // RateLimited EVEN when the body carries an error code that would
        // otherwise fall through to the generic Unknown mapping.
        $json = (string) json_encode([
            'error' => ['code' => 12345, 'message' => 'Too Many Requests'],
        ]);
        $client = new class ($json) implements ClientInterface {
            public function __construct(private readonly string $json) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(429, ['Content-Type' => 'application/json'], $this->json);
            }
        };
        $connector = $this->makeConnector($client);

        try {
            $connector->isochrone(new IsochroneOptions(
                center: new LatLng(32.08, 34.78),
                type: IsochroneType::Time,
                values: [300],
            ));
            self::fail('Expected ConnectorError.');
        } catch (ConnectorError $e) {
            self::assertSame(429, $e->statusCode);
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
        }
    }

    #[Test]
    public function isochroneHttp429WithoutBodyCodeMapsToRateLimited(): void
    {
        $json = (string) json_encode(['message' => 'Too Many Requests']);
        $client = new class ($json) implements ClientInterface {
            public function __construct(private readonly string $json) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(429, ['Content-Type' => 'application/json'], $this->json);
            }
        };
        $connector = $this->makeConnector($client);

        try {
            $connector->isochrone(new IsochroneOptions(
                center: new LatLng(32.08, 34.78),
                type: IsochroneType::Time,
                values: [300],
            ));
            self::fail('Expected ConnectorError.');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
        }
    }

    #[Test]
    public function isochroneSetsWalkingTimeTravelMode(): void
    {
        $client = $this->captureClient((string) json_encode([
            'saPolygons' => ['features' => []],
        ]));
        $connector = $this->makeConnector($client);

        $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300],
            travelMode: TravelMode::Walking,
        ));

        $body = (string) $client->captured[0]->getBody();
        self::assertStringContainsString('travelMode=Walking+Time', $body);
    }

    #[Test]
    public function isochroneUsesArcgisTokenWhenConfigured(): void
    {
        $client = $this->captureClient((string) json_encode([
            'saPolygons' => ['features' => []],
        ]));
        $factory = new HttpFactory();
        // dual-auth — arcgisToken path.
        $connector = new EsriIsochroneConnector(
            new EsriConfig(arcgisToken: 'token-xyz'),
            $client,
            $factory,
            $factory,
        );

        $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300],
        ));

        $body = (string) $client->captured[0]->getBody();
        self::assertStringContainsString('token=token-xyz', $body);
    }

    #[Test]
    public function isochroneThrowsWhenCapExceeded(): void
    {
        $client = $this->captureClient((string) json_encode([
            'saPolygons' => ['features' => []],
        ]));
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

    private function makeConnector(ClientInterface $client): EsriIsochroneConnector
    {
        $factory = new HttpFactory();
        $config = new EsriConfig(apiKey: 'test-api-key');

        return new EsriIsochroneConnector($config, $client, $factory, $factory);
    }
}
