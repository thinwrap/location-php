<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Mapbox;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Connector\Mapbox\MapboxGeocodingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\Enum\ProviderCode;

/**
 * HTTP error-mapping coverage for the Mapbox Geocoding connector
 * (422 maps to invalid_request, unlike the other providers).
 */
final class MapboxGeocodingConnectorErrorsTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private function connector(int $status, string $body, array $headers = []): MapboxGeocodingConnector
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

        return new MapboxGeocodingConnector(new MapboxConfig(accessToken: 'k'), $client, $factory, $factory);
    }

    /**
     * @return array<string, array{int, ProviderCode}>
     */
    public static function httpErrorCases(): array
    {
        return [
            '401' => [401, ProviderCode::AuthFailed],
            '403' => [403, ProviderCode::AuthFailed],
            '429' => [429, ProviderCode::RateLimited],
            '400' => [400, ProviderCode::InvalidRequest],
            '422' => [422, ProviderCode::InvalidRequest],
            '500' => [500, ProviderCode::ProviderUnavailable],
            '418' => [418, ProviderCode::Unknown],
        ];
    }

    #[Test]
    #[DataProvider('httpErrorCases')]
    public function geocodeMapsHttpErrors(int $status, ProviderCode $expected): void
    {
        try {
            $this->connector($status, '{"message":"nope"}')->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame($expected, $e->providerCode);
            self::assertSame($status, $e->statusCode);
        }
    }

    #[Test]
    public function errorSurfacesRetryAfter(): void
    {
        try {
            $this->connector(429, '{}', ['Content-Type' => 'application/json', 'Retry-After' => '9'])
                ->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
            self::assertStringContainsString('9', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function emptyFeaturesYieldsNoCandidates(): void
    {
        $result = $this->connector(200, '{"type":"FeatureCollection","features":[]}')
            ->geocode(new GeocodeOptions(address: 'nowhere'));
        self::assertSame([], $result->candidates);
    }

    #[Test]
    public function geocodeNormalizesFeaturesWithViewportAndFallbacks(): void
    {
        $body = json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                // full_address + mapbox_id + bbox -> viewport
                [
                    'properties' => ['full_address' => 'Full Addr', 'mapbox_id' => 'mid', 'bbox' => [19.0, 9.0, 21.0, 11.0]],
                    'geometry' => ['coordinates' => [20.0, 10.0]],
                ],
                // place_formatted fallback, no bbox
                [
                    'properties' => ['place_formatted' => 'Place Fmt'],
                    'geometry' => ['coordinates' => [1.0, 2.0]],
                ],
                // top-level place_name fallback
                ['place_name' => 'Old Name', 'geometry' => ['coordinates' => [3.0, 4.0]]],
                // no formatted at all -> skipped
                ['geometry' => ['coordinates' => [5.0, 6.0]]],
                // no coordinates -> skipped
                ['properties' => ['full_address' => 'no coords']],
            ],
        ]);

        $result = $this->connector(200, $body)->geocode(new GeocodeOptions(address: 'X'));

        self::assertCount(3, $result->candidates);
        self::assertSame('Full Addr', $result->candidates[0]->formattedAddress);
        self::assertSame('mid', $result->candidates[0]->placeId);
        self::assertSame(10.0, $result->candidates[0]->location->lat);
        self::assertNotNull($result->candidates[0]->viewport);
        self::assertSame(9.0, $result->candidates[0]->viewport->southwest->lat);
        self::assertSame(21.0, $result->candidates[0]->viewport->northeast->lng);
        self::assertSame('Place Fmt', $result->candidates[1]->formattedAddress);
        self::assertNull($result->candidates[1]->viewport);
        self::assertSame('Old Name', $result->candidates[2]->formattedAddress);
    }
}
