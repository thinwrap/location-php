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
use Thinwrap\Location\Connector\Esri\EsriGeocodingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Geocoding\AutocompleteOptions;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\DTO\Geocoding\ReverseGeocodeOptions;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Enum\ProviderCode;

final class EsriGeocodingConnectorTest extends TestCase
{
    #[Test]
    public function geocodeReturnsCorrectMultiResultList(): void
    {
        $json = (string) json_encode([
            'candidates' => [
                [
                    'address' => 'Tel Aviv',
                    'location' => ['x' => 34.78, 'y' => 32.08],
                    'extent' => ['xmin' => 34.7, 'ymin' => 32.0, 'xmax' => 34.9, 'ymax' => 32.1],
                    'attributes' => ['UniqueID' => 'abc'],
                ],
                [
                    'address' => 'Tel Aviv-Yafo',
                    'location' => ['x' => 34.77, 'y' => 32.05],
                ],
            ],
        ]);

        $connector = $this->makeConnector($this->captureClient($json));

        $result = $connector->geocode(new GeocodeOptions(address: 'Tel Aviv'));

        self::assertCount(2, $result->candidates);
        self::assertSame('Tel Aviv', $result->candidates[0]->formattedAddress);
        self::assertSame(32.08, $result->candidates[0]->location->lat);
        self::assertSame(34.78, $result->candidates[0]->location->lng);
        // Forward candidates: placeId intentionally null (UniqueID is not portable per TS 1.23).
        self::assertNull($result->candidates[0]->placeId);
        self::assertNotNull($result->candidates[0]->viewport);
        self::assertSame(32.0, $result->candidates[0]->viewport->southwest->lat);
        self::assertSame(34.9, $result->candidates[0]->viewport->northeast->lng);
        self::assertSame('Tel Aviv-Yafo', $result->candidates[1]->formattedAddress);
    }

    #[Test]
    public function reverseGeocodeWrapsSingleResultInCandidatesList(): void
    {
        $json = (string) json_encode([
            'address' => ['LongLabel' => 'Tel Aviv, Israel'],
            'location' => ['x' => 34.78, 'y' => 32.08],
        ]);

        $connector = $this->makeConnector($this->captureClient($json));

        $result = $connector->reverseGeocode(new ReverseGeocodeOptions(
            location: new LatLng(32.08, 34.78),
        ));

        // wrap: Esri's single feature wrapped as one-element candidates[].
        self::assertCount(1, $result->candidates);
        self::assertSame('Tel Aviv, Israel', $result->candidates[0]->formattedAddress);
        self::assertSame(32.08, $result->candidates[0]->location->lat);
        self::assertSame(34.78, $result->candidates[0]->location->lng);
        self::assertNull($result->candidates[0]->placeId);
        self::assertNull($result->candidates[0]->viewport);
    }

    #[Test]
    public function autocompleteMapsMagicKeyToPlaceId(): void
    {
        $json = (string) json_encode([
            'suggestions' => [
                [
                    'text' => 'Tel Aviv',
                    'magicKey' => 'abc123',
                    'isCollection' => false,
                ],
            ],
        ]);

        $connector = $this->makeConnector($this->captureClient($json));

        $result = $connector->autocomplete(new AutocompleteOptions(input: 'Tel Aviv'));

        self::assertCount(1, $result->predictions);
        self::assertSame('Tel Aviv', $result->predictions[0]->description);
        self::assertSame('abc123', $result->predictions[0]->placeId);
    }

    #[Test]
    public function twoHundredWithErrorBodyRaisesConnectorError(): void
    {
        // ArcGIS frequently returns HTTP 200 with `error: { code, message }`
        // for application-layer failures (e.g. invalid token: code 498).
        $json = (string) json_encode([
            'error' => ['code' => 498, 'message' => 'Invalid token.', 'details' => []],
        ]);

        $client = new class ($json) implements ClientInterface {
            public function __construct(private readonly string $json) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], $this->json);
            }
        };
        $connector = $this->makeConnector($client);

        try {
            $connector->geocode(new GeocodeOptions(address: 'Tel Aviv'));
            self::fail('Expected ConnectorError for 200-with-error-body.');
        } catch (ConnectorError $e) {
            self::assertSame(200, $e->statusCode);
            self::assertSame(ProviderCode::AuthFailed, $e->providerCode);
            self::assertStringContainsString('Invalid token', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function reverseGeocodeReturnsEmptyOnMissingAddress(): void
    {
        $json = (string) json_encode([
            'address' => [],
            'location' => ['x' => 34.78, 'y' => 32.08],
        ]);

        $connector = $this->makeConnector($this->captureClient($json));

        $result = $connector->reverseGeocode(new ReverseGeocodeOptions(
            location: new LatLng(32.08, 34.78),
        ));

        self::assertSame([], $result->candidates);
    }

    #[Test]
    public function httpFourTwentyNineWithGenericBodyCodeMapsToRateLimited(): void
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
            $connector->geocode(new GeocodeOptions(address: 'Tel Aviv'));
            self::fail('Expected ConnectorError.');
        } catch (ConnectorError $e) {
            self::assertSame(429, $e->statusCode);
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
        }
    }

    #[Test]
    public function httpFourTwentyNineWithoutBodyCodeMapsToRateLimited(): void
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
            $connector->geocode(new GeocodeOptions(address: 'Tel Aviv'));
            self::fail('Expected ConnectorError.');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
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

    private function makeConnector(ClientInterface $client): EsriGeocodingConnector
    {
        $factory = new HttpFactory();
        $config = new EsriConfig(apiKey: 'test-api-key');

        return new EsriGeocodingConnector($config, $client, $factory, $factory);
    }
}
