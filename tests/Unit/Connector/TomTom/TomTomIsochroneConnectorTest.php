<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\TomTom;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\TomTomConfig;
use Thinwrap\Location\Connector\TomTom\TomTomIsochroneConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Isochrone\IsochroneOptions;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Enum\IsochroneType;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;

final class TomTomIsochroneConnectorTest extends TestCase
{
    #[Test]
    public function singleBandPathIssuesOneRequestAndPopulatesRequestCount(): void
    {
        $client = $this->scriptedClient([
            self::okResponse([
                'reachableRange' => [
                    'center' => ['latitude' => 32.08, 'longitude' => 34.78],
                    'boundary' => [
                        ['latitude' => 32.09, 'longitude' => 34.79],
                        ['latitude' => 32.10, 'longitude' => 34.80],
                    ],
                ],
            ]),
        ]);
        $connector = $this->makeConnector($client);

        $result = $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [300],
        ));

        // single-band → one HTTP call. (PINNED): `_meta` is OMITTED for
        // single-call paths (present iff N > 1).
        self::assertCount(1, $client->captured);
        self::assertNull($result->_meta);
        self::assertCount(1, $result->contours);
        self::assertSame(300, (int) $result->contours[0]->value);
        self::assertSame('Polygon', $result->contours[0]->geometry['type']);

        // ring closure + [lng, lat] order.
        /** @var list<list<list<float>>> $coords */
        $coords = $result->contours[0]->geometry['coordinates'];
        $ring = $coords[0];
        self::assertCount(3, $ring);
        self::assertSame([34.79, 32.09], $ring[0]);
        self::assertSame([34.80, 32.10], $ring[1]);
        self::assertSame([34.79, 32.09], $ring[2]);
    }

    #[Test]
    public function multiBandPathIssuesOneRequestPerValue(): void
    {
        $client = $this->scriptedClient([
            self::okResponse(['reachableRange' => ['boundary' => [
                ['latitude' => 32.09, 'longitude' => 34.79],
                ['latitude' => 32.10, 'longitude' => 34.80],
            ]]]),
            self::okResponse(['reachableRange' => ['boundary' => [
                ['latitude' => 32.11, 'longitude' => 34.81],
                ['latitude' => 32.12, 'longitude' => 34.82],
            ]]]),
            self::okResponse(['reachableRange' => ['boundary' => [
                ['latitude' => 32.13, 'longitude' => 34.83],
                ['latitude' => 32.14, 'longitude' => 34.84],
            ]]]),
        ]);
        $connector = $this->makeConnector($client);

        $result = $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [900, 300, 600],
        ));

        // 3 HTTP calls, requestCount = 3.: `_meta` present iff N > 1.
        self::assertCount(3, $client->captured);
        self::assertSame(['requestCount' => 3], $result->_meta);

        // Sort: 300, 600, 900.
        self::assertCount(3, $result->contours);
        self::assertSame(300, (int) $result->contours[0]->value);
        self::assertSame(600, (int) $result->contours[1]->value);
        self::assertSame(900, (int) $result->contours[2]->value);
    }

    #[Test]
    public function multiBandFailFastStopsOnFirstError(): void
    {
        $client = $this->scriptedClient([
            self::okResponse(['reachableRange' => ['boundary' => [
                ['latitude' => 32.09, 'longitude' => 34.79],
            ]]]),
            new Response(429, ['Retry-After' => '7', 'Content-Type' => 'application/json'], (string) json_encode([
                'error' => ['description' => 'Too many requests'],
            ])),
            // This response should NEVER be consumed (fail-fast).
            self::okResponse(['reachableRange' => ['boundary' => [
                ['latitude' => 32.11, 'longitude' => 34.81],
            ]]]),
        ]);
        $connector = $this->makeConnector($client);

        try {
            $connector->isochrone(new IsochroneOptions(
                center: new LatLng(32.08, 34.78),
                type: IsochroneType::Time,
                values: [300, 600, 900],
            ));
            self::fail('Expected ConnectorError fail-fast on first failure.');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
            self::assertSame(429, $e->statusCode);
            self::assertCount(2, $client->captured, 'Third request must not be dispatched after fail-fast.');
            self::assertStringContainsString('retry after 7 seconds', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function isochroneThrowsWhenCapExceeded(): void
    {
        $client = $this->scriptedClient([]);
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
            self::assertSame([], $client->captured);
        }
    }

    #[Test]
    public function isochroneRejectsCyclingAtTheBaseDto(): void
    {
        /// (PINNED): `cycling` is rejected at the IsochroneOptions DTO with
        // `unsupported_travel_mode` — only driving/walking on the unified facade.
        // TomTom's native bicycle profile is still reachable via `_passthrough`.
        // (Previously this test asserted cycling reached the wire as `bicycle`.)
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
    public function isochroneUsesTimeBudgetForTime(): void
    {
        $client = $this->scriptedClient([
            self::okResponse(['reachableRange' => ['boundary' => []]]),
        ]);
        $connector = $this->makeConnector($client);

        $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Time,
            values: [600],
        ));

        $query = urldecode($client->captured[0]->getUri()->getQuery());
        self::assertStringContainsString('timeBudgetInSec=600', $query);
    }

    #[Test]
    public function isochroneUsesDistanceBudgetForDistance(): void
    {
        $client = $this->scriptedClient([
            self::okResponse(['reachableRange' => ['boundary' => []]]),
        ]);
        $connector = $this->makeConnector($client);

        $connector->isochrone(new IsochroneOptions(
            center: new LatLng(32.08, 34.78),
            type: IsochroneType::Distance,
            values: [5000],
        ));

        $query = urldecode($client->captured[0]->getUri()->getQuery());
        self::assertStringContainsString('distanceBudgetInMeters=5000', $query);
    }

    /**
     * @param array<string,mixed> $body
     */
    private static function okResponse(array $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    /**
     * @param list<ResponseInterface> $queue
     * @return ClientInterface&object{captured: list<RequestInterface>}
     */
    private function scriptedClient(array $queue): ClientInterface
    {
        return new class ($queue) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];

            /**
             * @param list<ResponseInterface> $queue
             */
            public function __construct(private array $queue) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $next = array_shift($this->queue);
                if ($next === null) {
                    throw new \LogicException('No more scripted responses queued.');
                }

                return $next;
            }
        };
    }

    private function makeConnector(ClientInterface $client): TomTomIsochroneConnector
    {
        $factory = new HttpFactory();
        $config = new TomTomConfig(apiKey: 'test-api-key');

        return new TomTomIsochroneConnector($config, $client, $factory, $factory);
    }
}
