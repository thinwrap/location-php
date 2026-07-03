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
use Thinwrap\Location\Connector\Esri\EsriMatrixConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\Enum\ProviderCode;

/**
 * Error-mapping coverage for the Esri Matrix connector.
 */
final class EsriMatrixConnectorErrorsTest extends TestCase
{
    private function connector(int $status, string $body): EsriMatrixConnector
    {
        $client = new class ($status, $body) implements ClientInterface {
            public function __construct(private readonly int $status, private readonly string $body) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response($this->status, ['Content-Type' => 'application/json'], $this->body);
            }
        };
        $factory = new HttpFactory();

        return new EsriMatrixConnector(new EsriConfig(apiKey: 'k'), $client, $factory, $factory);
    }

    private function call(EsriMatrixConnector $c): void
    {
        $c->matrix(new MatrixOptions(
            origins: [new LatLng(32.08, 34.78)],
            destinations: [new LatLng(32.10, 34.80)],
        ));
    }

    /**
     * @return array<string, array{int, ProviderCode}>
     */
    public static function inBodyErrorCodes(): array
    {
        return [
            '498 -> auth_failed' => [498, ProviderCode::AuthFailed],
            '400 -> invalid_request' => [400, ProviderCode::InvalidRequest],
            '429 -> rate_limited' => [429, ProviderCode::RateLimited],
            '500 -> provider_unavailable' => [500, ProviderCode::ProviderUnavailable],
        ];
    }

    #[Test]
    #[DataProvider('inBodyErrorCodes')]
    public function mapsInBodyErrorCodes(int $code, ProviderCode $expected): void
    {
        $body = json_encode(['error' => ['code' => $code, 'message' => 'esri-error']]);
        try {
            $this->call($this->connector(200, $body));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame($expected, $e->providerCode);
        }
    }

    #[Test]
    public function mapsHttpErrorStatus(): void
    {
        try {
            $this->call($this->connector(401, '{}'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::AuthFailed, $e->providerCode);
        }

        try {
            $this->call($this->connector(503, '{}'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
        }
    }
}
