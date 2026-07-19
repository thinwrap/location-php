<?php

declare(strict_types=1);

namespace Thinwrap\Location\Base;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\Enum\ProviderCode;

/**
 * Minimal HTTP base class for location connectors.
 *
 * PSR-18 client + PSR-17 factories with auto-discovery via php-http/discovery
 * (mirrors the TS BYO-fetch hook). Helpers return raw PSR-7 ResponseInterface;
 * per-connector subclasses parse bodies and translate HTTP errors into
 * ConnectorError instances. No casing-transform layer, no global
 * state.
 */
abstract class BaseConnector
{
    protected readonly ClientInterface $httpClient;
    protected readonly RequestFactoryInterface $requestFactory;
    protected readonly StreamFactoryInterface $streamFactory;

    public function __construct(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    abstract public function getProviderId(): string;

    /**
     * @param array<string, string> $headers
     * @param array<string, string|int|float|bool> $query
     */
    protected function sendGet(string $url, array $headers = [], array $query = []): ResponseInterface
    {
        $request = $this->requestFactory->createRequest('GET', $this->appendQuery($url, $query));

        return $this->dispatch($this->applyHeaders($request, $headers));
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string|int|float|bool> $query
     */
    protected function sendPostJson(
        string $url,
        mixed $body,
        array $headers = [],
        array $query = [],
    ): ResponseInterface {
        try {
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Failed to encode request body',
                cause: $exception,
                previous: $exception,
            );
        }
        $request = $this->requestFactory
            ->createRequest('POST', $this->appendQuery($url, $query))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($payload));

        return $this->dispatch($this->applyHeaders($request, $headers));
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $headers
     * @param array<string, string|int|float|bool> $query
     */
    protected function sendPostForm(
        string $url,
        array $form,
        array $headers = [],
        array $query = [],
    ): ResponseInterface {
        $request = $this->requestFactory
            ->createRequest('POST', $this->appendQuery($url, $query))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream(http_build_query($form)));

        return $this->dispatch($this->applyHeaders($request, $headers));
    }

    /**
     * @param array<string, string|int|float|bool> $query
     */
    private function appendQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }
        $queryString = http_build_query($query);

        return $url . (str_contains($url, '?') ? '&' : '?') . $queryString;
    }

    /**
     * @param array<string, string> $headers
     */
    private function applyHeaders(RequestInterface $request, array $headers): RequestInterface
    {
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function dispatch(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            // The raw PSR-18 client exception (e.g. Guzzle) embeds the full
            // request URL in its message, which for query-key providers IS the
            // credential (`?key=…`/`token=…`/`access_token=…`). Chaining that raw
            // Throwable as `cause`/`previous` would re-expose the secret via
            // `(string) $error`, `getPrevious()`, `error_log($error)`, or Monolog
            // `['exception' => $error]` — defeating the `providerMessage`
            // redaction (CWE-532). Surface only the redacted message plus a
            // non-sensitive descriptor (the transport exception class); never the
            // raw message/URL and never the Throwable itself.
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::ProviderUnavailable,
                providerMessage: $this->redactCredentials($exception->getMessage()),
                cause: ['raw' => ['exception' => $exception::class], 'retryAfter' => null],
            );
        }
    }

    /**
     * Redact the values of known credential query parameters from a message.
     *
     * The discovered PSR-18 client (e.g. Guzzle) embeds the full request URL in
     * its exception message, which can leak live credentials passed as query
     * params. Only the credential value is masked — the rest of the message
     * (e.g. "cURL error 6: Could not resolve host …") is preserved. The raw
     * exception itself is never attached to the ConnectorError (see dispatch()),
     * so the URL cannot re-surface through the exception chain.
     */
    private function redactCredentials(string $message): string
    {
        return (string) preg_replace(
            '/((?:access_token|apiKey|api_key|key|token|sig|signature)=)[^&\s"\']+/i',
            '$1[REDACTED]',
            $message,
        );
    }
}
