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
use Thinwrap\Location\Connector\TomTom\TomTomGeocodingConnector;
use Thinwrap\Location\DTO\Geocoding\AutocompleteOptions;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\DTO\Geocoding\ReverseGeocodeOptions;
use Thinwrap\Location\DTO\LatLng;

final class TomTomGeocodingConnectorTest extends TestCase
{
    #[Test]
    public function geocodeReturnsCorrectResultWithPathFormEncoding(): void
    {
        $json = (string) json_encode([
            'summary' => ['numResults' => 1, 'totalResults' => 1],
            'results' => [
                [
                    'type' => 'Geography',
                    'id' => 'abc',
                    'score' => 1,
                    'address' => ['freeformAddress' => 'Tel Aviv'],
                    'position' => ['lat' => 32.08, 'lon' => 34.78],
                ],
            ],
        ]);

        $client = $this->captureClient($json);
        $connector = $this->makeConnector($client);

        $result = $connector->geocode(new GeocodeOptions(address: '1600 Amphitheatre Parkway'));

        self::assertCount(1, $result->candidates);
        self::assertSame('Tel Aviv', $result->candidates[0]->formattedAddress);
        self::assertSame(32.08, $result->candidates[0]->location->lat);
        self::assertSame(34.78, $result->candidates[0]->location->lng);
        self::assertSame('abc', $result->candidates[0]->placeId);

        // path-form URL — `rawurlencode`d address before `.json`.
        $url = (string) $client->captured[0]->getUri();
        self::assertStringContainsString('api.tomtom.com/search/2/geocode/', $url);
        self::assertStringContainsString('1600%20Amphitheatre%20Parkway.json', $url);
        self::assertStringContainsString('key=test-api-key', $url);
    }

    #[Test]
    public function geocodeConvertsTopLeftBtmRightViewportToSouthwestNortheast(): void
    {
        // viewport conversion.
        // TomTom: topLeftPoint = (north, west), btmRightPoint = (south, east).
        $json = (string) json_encode([
            'results' => [
                [
                    'id' => 'tt-vp',
                    'address' => ['freeformAddress' => 'Tel Aviv'],
                    'position' => ['lat' => 32.08, 'lon' => 34.78],
                    'viewport' => [
                        'topLeftPoint' => ['lat' => 32.10, 'lon' => 34.70],
                        'btmRightPoint' => ['lat' => 32.00, 'lon' => 34.90],
                    ],
                ],
            ],
        ]);

        $connector = $this->makeConnector($this->captureClient($json));

        $result = $connector->geocode(new GeocodeOptions(address: 'Tel Aviv'));

        self::assertCount(1, $result->candidates);
        $vp = $result->candidates[0]->viewport;
        self::assertNotNull($vp);
        // southwest = (south, west) = (btmRightPoint.lat, topLeftPoint.lon).
        self::assertSame(32.00, $vp->southwest->lat);
        self::assertSame(34.70, $vp->southwest->lng);
        // northeast = (north, east) = (topLeftPoint.lat, btmRightPoint.lon).
        self::assertSame(32.10, $vp->northeast->lat);
        self::assertSame(34.90, $vp->northeast->lng);
    }

    #[Test]
    public function reverseGeocodeReturnsCandidatesListWithStringPosition(): void
    {
        $json = (string) json_encode([
            'summary' => ['numResults' => 1],
            'addresses' => [
                [
                    'address' => ['freeformAddress' => 'Tel Aviv, Israel'],
                    'position' => '32.08,34.78',
                    'id' => 'abc',
                ],
            ],
        ]);

        $client = $this->captureClient($json);
        $connector = $this->makeConnector($client);

        $result = $connector->reverseGeocode(new ReverseGeocodeOptions(
            location: new LatLng(32.08, 34.78),
        ));

        // reverse-geocode mirrors forward shape — `candidates[]`.
        self::assertCount(1, $result->candidates);
        self::assertSame('Tel Aviv, Israel', $result->candidates[0]->formattedAddress);
        self::assertSame('abc', $result->candidates[0]->placeId);
        self::assertSame(32.08, $result->candidates[0]->location->lat);
        self::assertSame(34.78, $result->candidates[0]->location->lng);

        $url = (string) $client->captured[0]->getUri();
        self::assertStringContainsString('search/2/reverseGeocode/32.08,34.78.json', $url);
    }

    #[Test]
    public function autocompleteReturnsCorrectResult(): void
    {
        $json = (string) json_encode([
            'summary' => ['numResults' => 1, 'totalResults' => 1],
            'results' => [
                [
                    'type' => 'POI',
                    'id' => 'abc',
                    'address' => ['freeformAddress' => 'Tel Aviv'],
                    'position' => ['lat' => 32.08, 'lon' => 34.78],
                    'poi' => ['name' => 'Cafe X'],
                ],
            ],
        ]);

        $connector = $this->makeConnector($this->captureClient($json));

        $result = $connector->autocomplete(new AutocompleteOptions(input: 'Tel Aviv'));

        self::assertCount(1, $result->predictions);
        self::assertSame('Cafe X, Tel Aviv', $result->predictions[0]->description);
        self::assertSame('abc', $result->predictions[0]->placeId);
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

    private function makeConnector(ClientInterface $client): TomTomGeocodingConnector
    {
        $factory = new HttpFactory();
        $config = new TomTomConfig(apiKey: 'test-api-key');

        return new TomTomGeocodingConnector($config, $client, $factory, $factory);
    }
}
