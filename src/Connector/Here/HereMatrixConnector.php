<?php

declare(strict_types=1);

namespace Thinwrap\Location\Connector\Here;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Location\Base\BaseConnector;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\Contract\MatrixConnectorInterface;
use Thinwrap\Location\DTO\Matrix\MatrixCell;
use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\DTO\Matrix\MatrixResult;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Util\Passthrough;

/**
 * HERE Matrix v8 connector — PHP mirror of the TS connector (architectural outlier #1).
 *
 * HERE Matrix v8 is ALWAYS async — every request runs through a three-call
 * submit-poll-retrieve cycle. (async polling locality), the polling
 * loop lives entirely inside this connector with no shared middleware.
 *
 *   1. `POST https://matrix.router.hereapi.com/v8/matrix?async=true&apiKey=…`
 *      submits the job; HERE returns `matrixId` + `statusUrl`.
 *   2. `GET <statusUrl>` is polled with exponential backoff (1s start, ×1.5,
 *      capped at 5s) until `state === 'completed'`, `state === 'failed'`, or
 *      the 60 s deadline expires.
 *   3. `GET <resultUrl>` retrieves the final 2D matrix payload, flattened to
 * `cells[]`.
 *
 * The PHP polling loop blocks the calling thread via {@see \usleep()}. PHP has
 * no native async without curl_multi or Swoole/Amp coroutines; for a 60 s
 * deadline with typical 1–3 poll cycles this is acceptable. Consumers needing
 * non-blocking matrix calls can wrap in their own async layer.
 *
 * Polling deadline override: `$options->passthrough->body['timeoutMs']`
 * (consumer-supplied, in milliseconds, mirrors TS `_passthrough.body.timeoutMs`).
 *
 * Per-connector error mapping: 401/403 → AuthFailed, 400 →
 * InvalidRequest, 429 → RateLimited, 5xx → ProviderUnavailable, other → Unknown.
 * Retry-After surfacing follows the shared template — parsed seconds in
 * `providerMessage`, raw header in `cause.retryAfter` by design.
 *
 * @phpstan-type HereMatrixStatus array{state?: string, resultUrl?: string, ...<string, mixed>}
 */
final class HereMatrixConnector extends BaseConnector implements MatrixConnectorInterface
{
    private const MATRIX_URL = 'https://matrix.router.hereapi.com/v8/matrix';
    private const POLL_INITIAL_DELAY_MS = 1000;
    private const POLL_MAX_DELAY_MS = 5000;
    private const POLL_BACKOFF_MULTIPLIER = 1.5;
    private const POLL_DEFAULT_DEADLINE_MS = 60_000;

    /** @var callable(int): void */
    private $sleepFn;

    /**
     * @param (callable(int): void)|null $sleepFn Optional sleep injection
     *        (microseconds). Tests pass a no-op to compress the polling loop.
     */
    public function __construct(
        private readonly HereConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?callable $sleepFn = null,
    ) {
        parent::__construct($httpClient, $requestFactory, $streamFactory);
        $this->sleepFn = $sleepFn ?? static function (int $microseconds): void {
            \usleep($microseconds);
        };
    }

    public function getProviderId(): string
    {
        return LocationProviderId::Here->value;
    }

    public function matrix(MatrixOptions $options): MatrixResult
    {
        $submitResult = $this->submit($options);
        $matrixId = $submitResult['matrixId'];
        $statusUrl = $this->validateProviderUrl($submitResult['statusUrl'], 'statusUrl');

        $resultUrl = $this->poll($matrixId, $statusUrl, $options);

        return $this->retrieve($resultUrl, $options);
    }

    /**
     * Step 1: submit the matrix job.
     *
     * @return array{matrixId: string, statusUrl: string}
     */
    private function submit(MatrixOptions $options): array
    {
        $origins = [];
        foreach ($options->origins as $o) {
            $origins[] = ['lat' => $o->lat, 'lng' => $o->lng];
        }
        $destinations = [];
        foreach ($options->destinations as $d) {
            $destinations[] = ['lat' => $d->lat, 'lng' => $d->lng];
        }

        /** @var array<string, mixed> $body */
        $body = [
            'origins' => $origins,
            'destinations' => $destinations,
            'regionDefinition' => ['type' => 'autoCircle'],
            'matrixAttributes' => ['travelTimes', 'distances'],
        ];

        $profile = $this->mapProfile($options->travelMode);
        if ($profile !== 'car') {
            $body['transportMode'] = $profile;
        }

        if ($options->avoidTolls) {
            $body['avoid'] = ['features' => ['tollRoad']];
        }

        if ($options->departureTime !== null) {
            $body['departureTime'] = $options->departureTime->format('c');
        }

        /** @var array<string, string|int|float|bool> $query */
        $query = [
            'apiKey' => $this->config->apiKey,
            'async' => 'true',
        ];

        $merged = Passthrough::merge($body, [], $query, $this->buildPassthroughBuckets($options));
        // The `timeoutMs` bucket is a wrapper-side knob, not a HERE wire field —
        // strip it so it never reaches the vendor.
        unset($merged['body']['timeoutMs']);

        $response = $this->sendPostJson(self::MATRIX_URL, $merged['body'], $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response, 'HERE Matrix submit');
        }

        $data = $this->decodeJson($response);
        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'HERE Matrix submit returned no JSON body',
                cause: $data,
            );
        }

        $matrixId = isset($data['matrixId']) && is_string($data['matrixId']) ? $data['matrixId'] : null;
        $statusUrl = isset($data['statusUrl']) && is_string($data['statusUrl']) ? $data['statusUrl'] : null;

        if ($matrixId === null || $statusUrl === null) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'HERE Matrix submit response missing matrixId or statusUrl',
                cause: $data,
            );
        }

        return ['matrixId' => $matrixId, 'statusUrl' => $statusUrl];
    }

    /**
     * Step 2: poll the status URL until completion, failure, or deadline.
     *
     * Returns the resolved `resultUrl` on completion. Raises
     * {@see ProviderCode::MatrixPollingTimeout} on deadline expiry with
     * `cause.matrixId` so callers can resume out-of-band.
     */
    private function poll(string $matrixId, string $statusUrl, MatrixOptions $options): string
    {
        $deadlineMs = $this->resolveDeadlineMs($options);
        $deadlineAt = (int) round(microtime(true) * 1000) + $deadlineMs;

        $delayMs = self::POLL_INITIAL_DELAY_MS;

        while (true) {
            $now = (int) round(microtime(true) * 1000);
            if ($now >= $deadlineAt) {
                break;
            }

            // Bound the next sleep so we never overshoot the deadline.
            $remainingMs = $deadlineAt - $now;
            $sleepMs = $delayMs < $remainingMs ? $delayMs : $remainingMs;
            ($this->sleepFn)($sleepMs * 1000);

            $delayMs = (int) min(self::POLL_MAX_DELAY_MS, (int) round($delayMs * self::POLL_BACKOFF_MULTIPLIER));

            $authHeaders = [];
            $statusResponse = $this->sendGet($statusUrl, $authHeaders, ['apiKey' => $this->config->apiKey]);

            // Real HERE v8 behavior: on completion the poll returns
            // `303 See Other` with `Location: <resultUrl>` and a body
            // `{status:"completed", resultUrl}`. Guzzle's PSR-18 `sendRequest`
            // forces `allow_redirects=false`, so the 303 surfaces here rather
            // than being auto-followed. It MUST be handled BEFORE the generic
            // `>= 300` error guard below (a 303 is not a success status and
            // would otherwise raise). resultUrl is read from the body
            // (preferred) or the `Location` response header.
            if ($statusResponse->getStatusCode() === 303) {
                return $this->requireResultUrl($this->decodeJson($statusResponse), $statusResponse);
            }

            if ($statusResponse->getStatusCode() >= 300) {
                $this->raiseHttpError($statusResponse, 'HERE Matrix poll');
            }

            $status = $this->decodeJson($statusResponse);
            $state = (is_array($status) && isset($status['status']) && is_string($status['status']))
                ? $status['status']
                : ((is_array($status) && isset($status['state']) && is_string($status['state'])) ? $status['state'] : null);

            // A 200 body carrying status "completed" is also treated as
            // completion (belt-and-braces alongside the 303 path above),
            // resolving resultUrl from the body or the `Location` header.
            if ($state === 'completed') {
                return $this->requireResultUrl($status, $statusResponse);
            }

            if ($state === 'failed') {
                throw new ConnectorError(
                    statusCode: $statusResponse->getStatusCode(),
                    providerCode: ProviderCode::ProviderUnavailable,
                    providerMessage: 'HERE Matrix job failed',
                    message: 'HERE Matrix job failed',
                    cause: $status,
                );
            }

            // Otherwise continue polling on 'pending'/'inProgress'/etc.
        }

        throw new ConnectorError(
            statusCode: null,
            providerCode: ProviderCode::MatrixPollingTimeout,
            providerMessage: "matrixId: {$matrixId}",
            message: 'HERE Matrix polling deadline exceeded',
            cause: ['matrixId' => $matrixId, 'statusUrl' => $statusUrl],
        );
    }

    /**
     * Resolve the async `resultUrl` from a completed poll response: the decoded
     * body's `resultUrl` (preferred; present in both the 303 body and any 200
     * "completed" body) or the `Location` response header (set on the 303).
     * Validates the resolved URL against the hereapi.com allow-list before
     * returning; raises a typed {@see ConnectorError} when neither is present.
     *
     * @param mixed $body Decoded poll body (may be null/scalar/array).
     */
    private function requireResultUrl(mixed $body, ResponseInterface $response): string
    {
        $fromBody = (is_array($body) && isset($body['resultUrl']) && is_string($body['resultUrl']) && $body['resultUrl'] !== '')
            ? $body['resultUrl']
            : null;
        $location = $response->getHeaderLine('Location');
        $resultUrl = $fromBody ?? ($location !== '' ? $location : null);

        if ($resultUrl === null) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'HERE Matrix poll completed without resultUrl',
                cause: is_array($body) ? $body : null,
            );
        }

        return $this->validateProviderUrl($resultUrl, 'resultUrl');
    }

    /**
     * Step 3: retrieve the final matrix payload and flatten to cells.
     */
    private function retrieve(string $resultUrl, MatrixOptions $options): MatrixResult
    {
        // Step 3a: GET the (already-validated hereapi.com) resultUrl WITH the
        // apiKey and `Accept-Encoding: gzip`. HERE hard-requires both — 401
        // Unauthorized without the key, 406 Not Acceptable without the gzip
        // header. On success HERE does NOT return the payload inline: it
        // responds `303 See Other` with `Location: <pre-signed S3 URL>`.
        // Guzzle's PSR-18 `sendRequest` forces `allow_redirects=false`, so that
        // 303 is observable here and we follow it MANUALLY below — the apiKey
        // is never forwarded off the HERE host to the storage backend.
        $response = $this->sendGet(
            $resultUrl,
            ['Accept-Encoding' => 'gzip'],
            ['apiKey' => $this->config->apiKey],
        );

        // Step 3b: follow the single redirect hop to the pre-signed result URL
        // WITHOUT the apiKey (or any HERE auth) — the signed URL is
        // self-authenticating (it carries its own query signature) and lives on
        // a non-HERE host, so it is intentionally NOT run through
        // validateProviderUrl and never receives the key. A direct 200 (no
        // redirect) is handled by simply skipping this hop.
        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            $location = $response->getHeaderLine('Location');
            if ($location === '') {
                throw new ConnectorError(
                    statusCode: $status,
                    providerCode: ProviderCode::Unknown,
                    providerMessage: 'HERE Matrix retrieve redirect missing Location header',
                );
            }
            // The redirect target is a non-HERE (pre-signed storage) host so it
            // isn't run through validateProviderUrl, but it MUST still be https —
            // refuse a plaintext/other-scheme downgrade.
            if (strtolower((string) parse_url($location, PHP_URL_SCHEME)) !== 'https') {
                throw new ConnectorError(
                    statusCode: $status,
                    providerCode: ProviderCode::Unknown,
                    providerMessage: 'HERE Matrix result redirect must be an https URL',
                );
            }
            $response = $this->sendGet($location, ['Accept-Encoding' => 'gzip']);
        }

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response, 'HERE Matrix retrieve');
        }

        $data = $this->readMatrixBody($response);
        if (!is_array($data) || !isset($data['matrix']) || !is_array($data['matrix'])) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'HERE Matrix retrieve missing matrix payload',
                cause: $data,
            );
        }

        $matrix = $data['matrix'];
        $numDestinations = isset($matrix['numDestinations']) && is_int($matrix['numDestinations'])
            ? $matrix['numDestinations']
            : count($options->destinations);
        $numOrigins = isset($matrix['numOrigins']) && is_int($matrix['numOrigins'])
            ? $matrix['numOrigins']
            : count($options->origins);
        $travelTimes = (isset($matrix['travelTimes']) && is_array($matrix['travelTimes'])) ? array_values($matrix['travelTimes']) : [];
        $distances = (isset($matrix['distances']) && is_array($matrix['distances'])) ? array_values($matrix['distances']) : [];
        // Per-cell status parallel to travelTimes/distances (0 = OK, 3 = usable
        // despite a violated constraint); any other non-zero code marks that
        // cell's value as unspecified.
        $errorCodes = (isset($matrix['errorCodes']) && is_array($matrix['errorCodes'])) ? array_values($matrix['errorCodes']) : [];

        // flatten uses the vendor's own dimensions as the single source for
        // both stride (numDestinations) and bound (numOrigins × numDestinations).
        // A flat array shorter than that product is a truncated/shape-mismatched
        // payload — treat it as malformed rather than zero-filling missing cells.
        $expected = $numOrigins * $numDestinations;
        if (count($travelTimes) < $expected || count($distances) < $expected) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'HERE Matrix retrieve returned a short matrix payload',
                cause: $data,
            );
        }

        $cells = [];
        for ($oi = 0; $oi < $numOrigins; $oi++) {
            for ($di = 0; $di < $numDestinations; $di++) {
                $index = $oi * $numDestinations + $di;
                // Omit cells HERE flagged as failed (errorCode not 0/3); their
                // travelTimes/distances value is unspecified. Contract: failed
                // entries are omitted from cells.
                $errorCode = $errorCodes[$index] ?? null;
                if ($errorCode !== null && $errorCode !== 0 && $errorCode !== 3) {
                    continue;
                }
                $cells[] = new MatrixCell(
                    originIndex: $oi,
                    destinationIndex: $di,
                    distanceMeters: self::toFloat($distances[$index] ?? 0),
                    durationSeconds: self::toFloat($travelTimes[$index] ?? 0),
                );
            }
        }

        return new MatrixResult(cells: $cells, raw: $data);
    }

    /**
     * Resolve the polling deadline (ms). Honors
     * `$options->passthrough->body['timeoutMs']` when present.
     */
    private function resolveDeadlineMs(MatrixOptions $options): int
    {
        $body = $options->passthrough?->body;
        if (is_array($body) && isset($body['timeoutMs'])) {
            $raw = $body['timeoutMs'];
            if (is_int($raw) && $raw > 0) {
                return $raw;
            }
            if (is_numeric($raw) && (int) $raw > 0) {
                return (int) $raw;
            }
        }

        return self::POLL_DEFAULT_DEADLINE_MS;
    }

    /**
     * Validate a provider-supplied async URL before attaching the API key.
     *
     * `statusUrl`/`resultUrl` are read verbatim from the HERE response body; a
     * tampered response could otherwise exfiltrate the key to an arbitrary host.
     * The scheme must be `https` and the host must match the submit endpoint's
     * host or be a subdomain of `hereapi.com`.
     */
    private function validateProviderUrl(string $url, string $field): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $submitHost = parse_url(self::MATRIX_URL, PHP_URL_HOST);

        $hostOk = is_string($host) && $host !== '' && (
            $host === $submitHost
            || $host === 'hereapi.com'
            || str_ends_with($host, '.hereapi.com')
        );

        if ($scheme !== 'https' || !$hostOk) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "HERE Matrix returned an untrusted {$field}",
            );
        }

        return $url;
    }

    /**
     * Map vendor HTTP status to {@see ProviderCode}. * Locality: per-connector.
     *
     * @param mixed $body Decoded vendor error body (may be null/scalar/array).
     */
    private function mapVendorError(int $httpStatus, mixed $body): ProviderCode
    {
        if ($httpStatus === 401 || $httpStatus === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($httpStatus === 429) {
            return ProviderCode::RateLimited;
        }
        if ($httpStatus === 400) {
            return ProviderCode::InvalidRequest;
        }
        if ($httpStatus >= 500 && $httpStatus < 600) {
            return ProviderCode::ProviderUnavailable;
        }

        return ProviderCode::Unknown;
    }

    /**
     * Build the human-readable `providerMessage` from the vendor body, weaving
     * in parsed Retry-After seconds when present by design.
     *
     * @param mixed $body Decoded vendor error body.
     */
    private function formatProviderMessage(mixed $body, ?string $retryAfter): ?string
    {
        $base = null;
        if (is_array($body)) {
            $title = $body['title'] ?? null;
            $cause = $body['cause'] ?? null;
            if (is_string($title) && $title !== '') {
                $base = $title;
                if (is_string($cause) && $cause !== '') {
                    $base .= ': ' . $cause;
                }
            } elseif (is_string($cause) && $cause !== '') {
                $base = $cause;
            } else {
                $error = $body['error'] ?? null;
                if (is_array($error) && isset($error['message']) && is_string($error['message']) && $error['message'] !== '') {
                    $base = $error['message'];
                } elseif (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
                    $base = $body['message'];
                }
            }
        }

        if ($retryAfter !== null && $retryAfter !== '' && is_numeric($retryAfter)) {
            $seconds = (int) $retryAfter;
            $suffix = "retry after {$seconds} seconds";

            return $base !== null ? "{$base}; {$suffix}" : $suffix;
        }

        return $base;
    }

    /**
     * Parse the response body + raise a {@see ConnectorError} for non-2xx.
     */
    private function raiseHttpError(ResponseInterface $response, string $label): never
    {
        $status = $response->getStatusCode();
        $body = $this->decodeJson($response);
        $retryAfter = $response->getHeaderLine('Retry-After');
        $retryAfter = $retryAfter === '' ? null : $retryAfter;

        if ($retryAfter !== null) {
            $cause = is_array($body)
                ? array_merge($body, ['retryAfter' => $retryAfter])
                : ['body' => $body, 'retryAfter' => $retryAfter];
        } else {
            $cause = $body;
        }

        throw new ConnectorError(
            statusCode: $status,
            providerCode: $this->mapVendorError($status, $body),
            providerMessage: $this->formatProviderMessage($body, $retryAfter),
            message: "{$label} failed: {$status}",
            cause: $cause,
        );
    }

    /**
     * Read + JSON-decode a response body. Returns null when the body is empty
     * or not valid JSON (mirrors the TS `.catch(() => null)` shape).
     */
    private function decodeJson(ResponseInterface $response): mixed
    {
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return null;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Read + JSON-decode the retrieve body, decompressing defensively.
     *
     * HERE serves the matrix result gzip-compressed and hard-requires the
     * `Accept-Encoding: gzip` request header (406 without it). Depending on the
     * PSR-18 client, the body arrives either as raw gzip bytes (gzip magic
     * `0x1f 0x8b`, `Content-Encoding: gzip`) or already inflated by the
     * transport. We `gzdecode()` only when the bytes actually look gzipped, so
     * an already-decompressed body (possibly still carrying a stray
     * `Content-Encoding` header) is left untouched. Kept LOCAL to this
     * connector per the per-connector-locality invariant.
     *
     * @return mixed Decoded JSON (array on success) or null when unparseable.
     */
    private function readMatrixBody(ResponseInterface $response): mixed
    {
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return null;
        }

        $encoding = strtolower($response->getHeaderLine('Content-Encoding'));
        $looksGzipped = str_contains($encoding, 'gzip')
            || (strlen($raw) >= 2 && $raw[0] === "\x1f" && $raw[1] === "\x8b");

        if ($looksGzipped) {
            $decoded = @gzdecode($raw);
            if (is_string($decoded)) {
                $raw = $decoded;
            }
            // A `false` return means the transport already inflated the body
            // but kept the Content-Encoding header — parse the bytes as-is.
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Convert the typed Passthrough DTO into the loose array shape that
     * {@see Passthrough::merge()} accepts (only present buckets are included).
     *
     * @return array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string|int|float|bool>}|null
     */
    private function buildPassthroughBuckets(MatrixOptions $options): ?array
    {
        $passthrough = $options->passthrough;
        if ($passthrough === null) {
            return null;
        }

        $body = $passthrough->body;
        $headers = $passthrough->headers;
        $query = $passthrough->query;
        if ($body === null && $headers === null && $query === null) {
            return null;
        }

        $bucket = [];
        if ($body !== null) {
            $bucket['body'] = $body;
        }
        if ($headers !== null) {
            $bucket['headers'] = $headers;
        }
        if ($query !== null) {
            $bucket['query'] = $query;
        }

        return $bucket;
    }

    private function mapProfile(TravelMode $mode): string
    {
        return match ($mode) {
            TravelMode::Walking => 'pedestrian',
            TravelMode::Cycling => 'bicycle',
            TravelMode::Driving => 'car',
        };
    }

    private static function toFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
