<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Base;

use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Connector\Google\GoogleGeocodingConnector;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Geocoding\AutocompleteOptions;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\Enum\ProviderCode;

/**
 * Coverage for the shared BaseConnector transport guards, exercised through a
 * concrete connector (sendPostJson encode failure + dispatch transport error).
 */
final class BaseConnectorTest extends TestCase
{
    #[Test]
    public function dispatchWrapsTransportErrorAsProviderUnavailable(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };
        $factory = new HttpFactory();
        $connector = new GoogleGeocodingConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

        try {
            $connector->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertStringContainsString('connection refused', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function dispatchRedactsCredentialQueryParamInTransportErrorMessage(): void
    {
        // Guzzle-style transport error embeds the full request URL (with a live
        // credential) in its message — only the credential value must be masked.
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('cURL error 6: Could not resolve host for https://api.example/geocode?access_token=SECRET123') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };
        $factory = new HttpFactory();
        $connector = new GoogleGeocodingConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

        try {
            $connector->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertStringContainsString('access_token=[REDACTED]', (string) $e->providerMessage);
            self::assertStringNotContainsString('SECRET123', (string) $e->providerMessage);
            // The non-credential remainder of the message is preserved.
            self::assertStringContainsString('Could not resolve host', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function dispatchLeavesMessageWithoutCredentialParamUnchanged(): void
    {
        // No credential query param present → the redaction pass-through branch
        // leaves the message verbatim.
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('cURL error 7: Failed to connect to api.example port 443') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };
        $factory = new HttpFactory();
        $connector = new GoogleGeocodingConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

        try {
            $connector->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertSame('cURL error 7: Failed to connect to api.example port 443', (string) $e->providerMessage);
            self::assertStringNotContainsString('[REDACTED]', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function dispatchDoesNotLeakCredentialThroughExceptionChain(): void
    {
        // Regression (CWE-532): the raw PSR-18 exception embeds the full request
        // URL — including the live `key=` credential — in its message. It must
        // NOT be chained as previous/cause, or `(string) $e`, getPrevious(), and
        // log-framework serialization would re-expose the redacted secret.
        $secret = 'SUPERSECRETKEY123';
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('cURL error 6: Could not resolve host for https://maps.example/geocode?address=X&key=SUPERSECRETKEY123') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };
        $factory = new HttpFactory();
        $connector = new GoogleGeocodingConnector(new GoogleConfig(apiKey: $secret), $client, $factory, $factory);

        try {
            $connector->geocode(new GeocodeOptions(address: 'X'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);

            // No raw Throwable is chained into the exception's `previous` slot.
            self::assertNull($e->getPrevious());

            // The secret must not surface anywhere reachable from the error:
            // the message, the full string-cast, or the public `cause` payload.
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringNotContainsString($secret, (string) $e);
            self::assertStringNotContainsString($secret, var_export($e->cause, true));

            // A non-sensitive descriptor (the transport exception class) is kept
            // in `cause` in place of the raw Throwable.
            self::assertIsArray($e->cause);
            self::assertArrayHasKey('raw', $e->cause);
        }
    }

    #[Test]
    public function sendPostJsonRejectsUnencodableBody(): void
    {
        // A never-called client: the JSON encode failure happens before dispatch.
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \LogicException('should not be reached');
            }
        };
        $factory = new HttpFactory();
        $connector = new GoogleGeocodingConnector(new GoogleConfig(apiKey: 'k'), $client, $factory, $factory);

        // Invalid UTF-8 in the merged POST body makes json_encode() throw.
        $options = new AutocompleteOptions(
            input: 'x',
            passthrough: new Passthrough(body: ['bad' => "\xB1\x31"]),
        );

        try {
            $connector->autocomplete($options);
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertStringContainsString('encode', (string) $e->providerMessage);
        }
    }
}
