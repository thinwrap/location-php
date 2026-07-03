<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Connector\Here;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\Connector\Here\HereGeocodingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\Enum\ProviderCode;

/**
 * HTTP error-mapping coverage for the HERE Geocoding connector.
 */
final class HereGeocodingConnectorErrorsTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private function connector(int $status, string $body, array $headers = []): HereGeocodingConnector
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

        return new HereGeocodingConnector(new HereConfig(apiKey: 'k'), $client, $factory, $factory);
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
            '500' => [500, ProviderCode::ProviderUnavailable],
            '503' => [503, ProviderCode::ProviderUnavailable],
            '418' => [418, ProviderCode::Unknown],
        ];
    }

    #[Test]
    #[DataProvider('httpErrorCases')]
    public function geocodeMapsHttpErrors(int $status, ProviderCode $expected): void
    {
        try {
            $this->connector($status, '{"error":"nope"}')->geocode(new GeocodeOptions(address: 'X'));
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
            $this->connector(429, '{}', ['Content-Type' => 'application/json', 'Retry-After' => '12'])
                ->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
            self::assertStringContainsString('12', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function emptyItemsYieldsNoCandidates(): void
    {
        $result = $this->connector(200, '{"items":[]}')->geocode(new GeocodeOptions(address: 'nowhere'));
        self::assertSame([], $result->candidates);
    }
}
