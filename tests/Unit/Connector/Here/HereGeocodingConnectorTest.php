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
use Thinwrap\Location\Connector\Here\HereGeocodingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Geocoding\AutocompleteOptions;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\DTO\Geocoding\ReverseGeocodeOptions;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Enum\ProviderCode;

final class HereGeocodingConnectorTest extends TestCase
{
    #[Test]
    public function geocodeReturnsCorrectResult(): void
    {
        $json = (string) json_encode([
            'items' => [
                [
                    'title' => 'Tel Aviv, Israel',
                    'address' => ['label' => 'Tel Aviv, Israel'],
                    'position' => ['lat' => 32.08, 'lng' => 34.78],
                    'id' => 'abc',
                    'mapView' => ['south' => 32.0, 'west' => 34.7, 'north' => 32.1, 'east' => 34.9],
                ],
            ],
        ]);

        $connector = $this->makeConnector($this->captureClient($json));

        $result = $connector->geocode(new GeocodeOptions(address: 'Tel Aviv'));

        self::assertCount(1, $result->candidates);
        self::assertSame('Tel Aviv, Israel', $result->candidates[0]->formattedAddress);
        self::assertSame(32.08, $result->candidates[0]->location->lat);
        self::assertSame(34.78, $result->candidates[0]->location->lng);
        self::assertSame('abc', $result->candidates[0]->placeId);
        self::assertNotNull($result->candidates[0]->viewport);
        self::assertSame(32.0, $result->candidates[0]->viewport->southwest->lat);
        self::assertSame(34.9, $result->candidates[0]->viewport->northeast->lng);
    }

    #[Test]
    public function reverseGeocodeReturnsCandidatesList(): void
    {
        $json = (string) json_encode([
            'items' => [
                [
                    'title' => 'Tel Aviv, Israel',
                    'address' => ['label' => 'Tel Aviv, Israel'],
                    'position' => ['lat' => 32.08, 'lng' => 34.78],
                    'id' => 'abc',
                ],
            ],
        ]);

        $connector = $this->makeConnector($this->captureClient($json));

        $result = $connector->reverseGeocode(new ReverseGeocodeOptions(
            location: new LatLng(32.08, 34.78),
        ));

        // reverse-geocode mirrors forward shape — `candidates[]` not single result.
        self::assertCount(1, $result->candidates);
        self::assertSame('Tel Aviv, Israel', $result->candidates[0]->formattedAddress);
        self::assertSame('abc', $result->candidates[0]->placeId);
    }

    #[Test]
    public function autocompleteReturnsCorrectResult(): void
    {
        $json = (string) json_encode([
            'items' => [
                [
                    'title' => 'Tel Aviv, Israel',
                    'id' => 'abc',
                    'address' => ['label' => 'Tel Aviv, Israel'],
                ],
            ],
        ]);

        $client = $this->captureClient($json);
        $connector = $this->makeConnector($client);

        $result = $connector->autocomplete(new AutocompleteOptions(input: 'Tel Aviv'));

        self::assertCount(1, $result->predictions);
        self::assertSame('Tel Aviv, Israel', $result->predictions[0]->description);
        self::assertSame('abc', $result->predictions[0]->placeId);
        self::assertStringContainsString('autosuggest.search.hereapi.com/v1/autosuggest', (string) $client->captured[0]->getUri());
    }

    #[Test]
    public function geocodeTranslatesAlpha2CountryFilterToAlpha3(): void
    {
        $client = $this->captureClient((string) json_encode(['items' => []]));
        $connector = $this->makeConnector($client);

        $connector->geocode(new GeocodeOptions(
            address: 'Toronto',
            countryFilter: ['US', 'CA'],
        ));

        $query = $client->captured[0]->getUri()->getQuery();
        // Expect `in=countryCode:USA,CAN` (URL-encoded: %3A and %2C).
        self::assertMatchesRegularExpression('/in=countryCode%3AUSA%2CCAN/', $query);
    }

    #[Test]
    public function unmappedCountryCodeRaisesInvalidRequestConnectorError(): void
    {
        $client = $this->captureClient('{}');
        $connector = $this->makeConnector($client);

        try {
            $connector->geocode(new GeocodeOptions(
                address: 'Anywhere',
                countryFilter: ['ZZ'],
            ));
            self::fail('Expected ConnectorError to be thrown for unmapped country code.');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertStringContainsString('ZZ', (string) $e->providerMessage);
            self::assertSame([], $client->captured, 'No HTTP request should be made when ISO translation fails.');
        }
    }

    #[Test]
    public function autocompleteWithLocationAndRadiusBuildsCircleProximity(): void
    {
        $client = $this->captureClient((string) json_encode(['items' => []]));
        $connector = $this->makeConnector($client);

        $connector->autocomplete(new AutocompleteOptions(
            input: 'Tel',
            location: new LatLng(32.08, 34.78),
            radius: 1000.0,
        ));

        $query = $client->captured[0]->getUri()->getQuery();
        // `in=circle:32.08,34.78;r=1000` (URL-encoded).
        self::assertStringContainsString('in=circle', $query);
        self::assertStringContainsString('32.08', $query);
        self::assertStringContainsString('34.78', $query);
        self::assertStringContainsString('r%3D1000', $query);
    }

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

    private function makeConnector(ClientInterface $client): HereGeocodingConnector
    {
        $factory = new HttpFactory();
        $config = new HereConfig(apiKey: 'test-api-key');

        return new HereGeocodingConnector($config, $client, $factory, $factory);
    }
}
