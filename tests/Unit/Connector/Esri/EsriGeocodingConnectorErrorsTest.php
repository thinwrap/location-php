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
use Thinwrap\Location\Connector\Esri\EsriGeocodingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\Enum\ProviderCode;

/**
 * Error-mapping coverage for the Esri Geocoding connector. ArcGIS commonly
 * returns HTTP 200 with an in-body { error: { code } } object, so both the
 * HTTP-status and in-body-code paths are exercised.
 */
final class EsriGeocodingConnectorErrorsTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private function connector(int $status, string $body, array $headers = []): EsriGeocodingConnector
    {
        $client = new class ($status, $body, $headers ?: ['Content-Type' => 'application/json']) implements ClientInterface {
            /** @param array<string, string> $headers */
            public function __construct(
                private readonly int $status,
                private readonly string $body,
                private readonly array $headers,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response($this->status, $this->headers, $this->body);
            }
        };
        $factory = new HttpFactory();

        return new EsriGeocodingConnector(new EsriConfig(apiKey: 'k'), $client, $factory, $factory);
    }

    /**
     * @return array<string, array{int, ProviderCode}>
     */
    public static function inBodyErrorCodes(): array
    {
        return [
            '498 invalid token -> auth_failed' => [498, ProviderCode::AuthFailed],
            '499 token required -> auth_failed' => [499, ProviderCode::AuthFailed],
            '403 -> auth_failed' => [403, ProviderCode::AuthFailed],
            '400 -> invalid_request' => [400, ProviderCode::InvalidRequest],
            '404 -> invalid_request' => [404, ProviderCode::InvalidRequest],
            '429 -> rate_limited' => [429, ProviderCode::RateLimited],
            '500 -> provider_unavailable' => [500, ProviderCode::ProviderUnavailable],
        ];
    }

    #[Test]
    #[DataProvider('inBodyErrorCodes')]
    public function geocodeMapsInBodyErrorCodes(int $code, ProviderCode $expected): void
    {
        $body = json_encode(['error' => ['code' => $code, 'message' => 'esri-error']]);
        try {
            $this->connector(200, $body)->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame($expected, $e->providerCode);
        }
    }

    #[Test]
    public function geocodeMapsHttpErrorStatus(): void
    {
        try {
            $this->connector(401, '{}')->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::AuthFailed, $e->providerCode);
            self::assertSame(401, $e->statusCode);
        }

        try {
            $this->connector(503, '{}')->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
        }
    }

    #[Test]
    public function geocodeNonJsonBodyRaisesUnknown(): void
    {
        try {
            $this->connector(200, 'not-json')->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::Unknown, $e->providerCode);
        }
    }

    #[Test]
    public function geocodeNoCandidatesYieldsEmpty(): void
    {
        $result = $this->connector(200, '{"candidates":[]}')->geocode(new GeocodeOptions(address: 'nowhere'));
        self::assertSame([], $result->candidates);
    }

    #[Test]
    public function geocodeNormalizesCandidatesWithExtentAndSkips(): void
    {
        $body = json_encode([
            'candidates' => [
                // address + location + extent -> viewport
                [
                    'address' => 'A',
                    'location' => ['x' => 34.0, 'y' => 32.0],
                    'extent' => ['xmin' => 33.0, 'ymin' => 31.0, 'xmax' => 35.0, 'ymax' => 33.0],
                ],
                // no extent -> viewport null
                ['address' => 'B', 'location' => ['x' => 1.0, 'y' => 2.0]],
                // no location -> skipped
                ['address' => 'C'],
            ],
        ]);

        $result = $this->connector(200, $body)->geocode(new GeocodeOptions(address: 'X'));

        self::assertCount(2, $result->candidates);
        self::assertSame('A', $result->candidates[0]->formattedAddress);
        self::assertSame(32.0, $result->candidates[0]->location->lat);
        self::assertNotNull($result->candidates[0]->viewport);
        self::assertSame(31.0, $result->candidates[0]->viewport->southwest->lat);
        self::assertSame(35.0, $result->candidates[0]->viewport->northeast->lng);
        self::assertNull($result->candidates[1]->viewport);
    }

    #[Test]
    public function reverseGeocodeMapsLongLabelAndLocation(): void
    {
        $body = json_encode([
            'address' => ['LongLabel' => '1600 Amphitheatre Pkwy'],
            'location' => ['x' => -122.08, 'y' => 37.42],
        ]);

        $result = $this->connector(200, $body)->reverseGeocode(
            new \Thinwrap\Location\DTO\Geocoding\ReverseGeocodeOptions(location: new \Thinwrap\Location\DTO\LatLng(37.42, -122.08)),
        );

        self::assertCount(1, $result->candidates);
        self::assertSame('1600 Amphitheatre Pkwy', $result->candidates[0]->formattedAddress);
        self::assertSame(37.42, $result->candidates[0]->location->lat);
    }

    #[Test]
    public function reverseGeocodeWithoutAddressYieldsEmpty(): void
    {
        $result = $this->connector(200, '{"location":{"x":1.0,"y":2.0}}')->reverseGeocode(
            new \Thinwrap\Location\DTO\Geocoding\ReverseGeocodeOptions(location: new \Thinwrap\Location\DTO\LatLng(2.0, 1.0)),
        );
        self::assertSame([], $result->candidates);
    }
}
