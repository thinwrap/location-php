<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Google;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Connector\Google\GoogleGeocodingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Geocoding\AutocompleteOptions;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\DTO\Geocoding\ReverseGeocodeOptions;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Enum\ProviderCode;

/**
 * Error-mapping + edge-case coverage for the Google Geocoding connector
 * (the happy paths live in GoogleGeocodingConnectorTest).
 */
final class GoogleGeocodingConnectorErrorsTest extends TestCase
{
    /**
     * @var list<RequestInterface>
     */
    public array $captured = [];

    /**
     * @param array<string, string> $headers
     */
    private function connector(int $status, string $body, array $headers = []): GoogleGeocodingConnector
    {
        $captured = &$this->captured;
        $client = new class ($status, $body, $headers ?: ['Content-Type' => 'application/json'], $captured) implements ClientInterface {
            /**
             * @param array<string, string> $headers
             * @param list<RequestInterface> $captured
             */
            public function __construct(
                private readonly int $status,
                private readonly string $body,
                private readonly array $headers,
                private array &$captured,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                return new Response($this->status, $this->headers, $this->body);
            }
        };

        $factory = new HttpFactory();

        return new GoogleGeocodingConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);
    }

    /**
     * @return array<string, array{int, string, ProviderCode}>
     */
    public static function httpErrorCases(): array
    {
        return [
            '401 -> auth_failed'            => [401, '{}', ProviderCode::AuthFailed],
            '403 -> auth_failed'            => [403, '{}', ProviderCode::AuthFailed],
            '429 -> rate_limited'           => [429, '{}', ProviderCode::RateLimited],
            '400 -> invalid_request'        => [400, '{}', ProviderCode::InvalidRequest],
            '500 -> provider_unavailable'   => [500, '{}', ProviderCode::ProviderUnavailable],
            '503 -> provider_unavailable'   => [503, '{}', ProviderCode::ProviderUnavailable],
            '418 -> unknown'                => [418, '{}', ProviderCode::Unknown],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('httpErrorCases')]
    public function geocodeMapsHttpErrorsToProviderCode(int $status, string $body, ProviderCode $expected): void
    {
        try {
            $this->connector($status, $body)->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame($expected, $e->providerCode);
            self::assertSame($status, $e->statusCode);
        }
    }

    #[Test]
    public function http403WithQuotaExceededMapsToRateLimited(): void
    {
        try {
            $this->connector(403, json_encode(['status' => 'QUOTA_EXCEEDED']))
                ->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
        }
    }

    /**
     * @return array<string, array{string, ProviderCode}>
     */
    public static function inBodyStatusCases(): array
    {
        return [
            'REQUEST_DENIED -> auth_failed'      => ['REQUEST_DENIED', ProviderCode::AuthFailed],
            'OVER_QUERY_LIMIT -> rate_limited'   => ['OVER_QUERY_LIMIT', ProviderCode::RateLimited],
            'INVALID_REQUEST -> invalid_request' => ['INVALID_REQUEST', ProviderCode::InvalidRequest],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('inBodyStatusCases')]
    public function geocodeRaisesOnNonOkBodyStatus(string $googleStatus, ProviderCode $expected): void
    {
        $body = json_encode(['status' => $googleStatus, 'error_message' => 'boom']);
        try {
            $this->connector(200, $body)->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame($expected, $e->providerCode);
            self::assertStringContainsString('boom', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function geocodeZeroResultsYieldsEmptyCandidates(): void
    {
        $body = json_encode(['status' => 'ZERO_RESULTS', 'results' => []]);
        $result = $this->connector(200, $body)->geocode(new GeocodeOptions(address: 'nowhere'));
        self::assertSame([], $result->candidates);
    }

    #[Test]
    public function geocodeNonJsonBodyRaisesUnknown(): void
    {
        try {
            $this->connector(200, 'not json at all')->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::Unknown, $e->providerCode);
            self::assertStringContainsString('non-JSON', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function errorSurfacesRetryAfterInMessageAndCause(): void
    {
        $body = json_encode(['status' => 'OVER_QUERY_LIMIT', 'error_message' => 'slow down']);
        try {
            $this->connector(429, $body, ['Content-Type' => 'application/json', 'Retry-After' => '30'])
                ->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
            self::assertStringContainsString('retry after 30 seconds', (string) $e->providerMessage);
            self::assertIsArray($e->cause);
            self::assertSame('30', $e->cause['retryAfter']);
        }
    }

    #[Test]
    public function geocodeRejectsInvalidCountryFilterEntry(): void
    {
        try {
            $this->connector(200, '{"status":"OK","results":[]}')
                ->geocode(new GeocodeOptions(address: 'X', countryFilter: ['USA']));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertStringContainsString('countryFilter', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function geocodeMapsViewportAndSkipsCoordinatelessResults(): void
    {
        $body = json_encode([
            'status' => 'OK',
            'results' => [
                ['formatted_address' => 'no-coords'], // skipped (no geometry.location)
                [
                    'formatted_address' => 'with-viewport',
                    'place_id' => 'pid',
                    'geometry' => [
                        'location' => ['lat' => 10.0, 'lng' => 20.0],
                        'viewport' => [
                            'southwest' => ['lat' => 9.0, 'lng' => 19.0],
                            'northeast' => ['lat' => 11.0, 'lng' => 21.0],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->connector(200, $body)->geocode(new GeocodeOptions(address: 'X'));

        self::assertCount(1, $result->candidates);
        $c = $result->candidates[0];
        self::assertSame('with-viewport', $c->formattedAddress);
        self::assertNotNull($c->viewport);
        self::assertSame(9.0, $c->viewport->southwest->lat);
        self::assertSame(11.0, $c->viewport->northeast->lat);
    }

    #[Test]
    public function reverseGeocodeRejectsNonFiniteLocation(): void
    {
        $this->expectException(\Throwable::class);
        $this->connector(200, '{"status":"OK","results":[]}')
            ->reverseGeocode(new ReverseGeocodeOptions(location: new LatLng(NAN, 34.0)));
    }

    #[Test]
    public function autocompleteSendsLocationBiasCircle(): void
    {
        $this->connector(200, '{"suggestions":[]}')
            ->autocomplete(new AutocompleteOptions(
                input: 'cafe',
                location: new LatLng(32.0, 34.0),
                radius: 1500.0,
            ));

        self::assertCount(1, $this->captured);
        $sent = (string) $this->captured[0]->getBody();
        self::assertStringContainsString('"locationBias"', $sent);
        self::assertStringContainsString('"radius":1500', $sent);
        self::assertStringContainsString('"latitude":32', $sent);
    }

    #[Test]
    public function autocompleteSkipsMalformedSuggestions(): void
    {
        $body = json_encode([
            'suggestions' => [
                ['queryPrediction' => ['text' => ['text' => 'ignored']]], // no placePrediction
                ['placePrediction' => ['placeId' => 'ok', 'text' => ['text' => 'Kept']]],
            ],
        ]);

        $result = $this->connector(200, $body)->autocomplete(new AutocompleteOptions(input: 'x'));

        self::assertCount(1, $result->predictions);
        self::assertSame('Kept', $result->predictions[0]->description);
        self::assertSame('ok', $result->predictions[0]->placeId);
    }
}
