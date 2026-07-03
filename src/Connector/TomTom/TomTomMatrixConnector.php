<?php

declare(strict_types=1);

namespace Thinwrap\Location\Connector\TomTom;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Location\Base\BaseConnector;
use Thinwrap\Location\Config\TomTomConfig;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\Contract\MatrixConnectorInterface;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Matrix\MatrixCell;
use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\DTO\Matrix\MatrixResult;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Util\Passthrough;

/**
 * TomTom Matrix v2 connector — PHP mirror of the TS connector (architectural
 * outlier #2 for Matrix).
 *
 * Unlike HERE Matrix v8 (always async) and Google/Mapbox/Esri
 * (always sync), TomTom Matrix v2 is **conditionally async** — dispatch is
 * driven by the cell-count threshold:
 *
 *   - `origins.length * destinations.length <= 2500` → single sync POST to
 *     `https://api.tomtom.com/routing/matrix/2`; the response carries `data[]`
 *     directly and is flattened to {@see MatrixResult::$cells}.
 *   - `> 2500` → submit-poll-retrieve via `/routing/matrix/2/async`:
 *       1. `POST /async?key=…` returns `{ jobId }`.
 *       2. `GET /async/{jobId}?key=…` is polled with exponential backoff
 *          (1s start, ×1.5, capped at 5s) until `state === 'Succeeded'`,
 *          `state === 'Failed'`, or the 60s deadline expires.
 *       3. `GET /async/{jobId}/result?key=…` retrieves the same `data[]` shape
 *          as the sync path.
 *
 * (async polling locality), the polling loop lives entirely inside
 * this connector with no shared middleware (parity with {@see \Thinwrap\Location\Connector\Here\HereMatrixConnector}).
 *
 * Polling deadline override: `$options->passthrough->body['timeoutMs']`
 * (consumer-supplied, in milliseconds, mirrors TS `_passthrough.body.timeoutMs`).
 * The `timeoutMs` key is stripped from the wire body so it never reaches the
 * vendor.
 *
 * Per-connector error mapping: 401/403 → AuthFailed, 400 →
 * InvalidRequest, 429 → RateLimited, 5xx → ProviderUnavailable, other →
 * Unknown. Retry-After surfaces via parsed seconds in `providerMessage` + raw
 * header in `cause.retryAfter` by design
 * (no structured `retryAfterSeconds` field).
 */
final class TomTomMatrixConnector extends BaseConnector implements MatrixConnectorInterface
{
    private const SYNC_MATRIX_URL = 'https://api.tomtom.com/routing/matrix/2';
    private const ASYNC_MATRIX_URL = 'https://api.tomtom.com/routing/matrix/2/async';
    private const SYNC_CELL_THRESHOLD = 2500;
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
        private readonly TomTomConfig $config,
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
        return LocationProviderId::TomTom->value;
    }

    public function matrix(MatrixOptions $options): MatrixResult
    {
        $cellCount = count($options->origins) * count($options->destinations);

        if ($cellCount <= self::SYNC_CELL_THRESHOLD) {
            return $this->matrixSync($options);
        }

        return $this->matrixAsync($options);
    }

    /**
     * Sync path (≤2500 cells): single POST to `/routing/matrix/2`.
     */
    private function matrixSync(MatrixOptions $options): MatrixResult
    {
        [$body, $query] = $this->buildRequest($options);

        $merged = Passthrough::merge($body, [], $query, $this->buildPassthroughBuckets($options));
        // `timeoutMs` is a wrapper-side knob, not a TomTom wire field — strip
        // it so it never reaches the vendor (parity with the HERE connector).
        unset($merged['body']['timeoutMs']);

        $response = $this->sendPostJson(self::SYNC_MATRIX_URL, $merged['body'], $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response, 'TomTom Matrix sync');
        }

        $data = $this->decodeJson($response);
        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'TomTom Matrix sync returned non-JSON body',
                cause: $data,
            );
        }

        return $this->normalizeCells($data);
    }

    /**
     * Async path (>2500 cells): submit → poll → retrieve.
     */
    private function matrixAsync(MatrixOptions $options): MatrixResult
    {
        $jobId = $this->submitAsync($options);
        $this->pollAsync($jobId, $options);

        return $this->retrieveAsync($jobId);
    }

    /**
     * Step 1: submit async matrix job.
     */
    private function submitAsync(MatrixOptions $options): string
    {
        [$body, $query] = $this->buildRequest($options);

        $merged = Passthrough::merge($body, [], $query, $this->buildPassthroughBuckets($options));
        unset($merged['body']['timeoutMs']);

        $response = $this->sendPostJson(self::ASYNC_MATRIX_URL, $merged['body'], $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response, 'TomTom Matrix submit');
        }

        $data = $this->decodeJson($response);
        $jobId = (is_array($data) && isset($data['jobId']) && is_string($data['jobId']) && $data['jobId'] !== '')
            ? $data['jobId']
            : null;

        if ($jobId === null) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'TomTom Matrix submit response missing jobId',
                cause: $data,
            );
        }

        return $jobId;
    }

    /**
     * Step 2: poll the async job until `Succeeded`, `Failed`, or deadline.
     *
     * Raises {@see ProviderCode::MatrixPollingTimeout} on deadline expiry with
     * `cause.jobId` so callers can resume out-of-band.
     */
    private function pollAsync(string $jobId, MatrixOptions $options): void
    {
        $deadlineMs = $this->resolveDeadlineMs($options);
        $deadlineAt = (int) round(microtime(true) * 1000) + $deadlineMs;

        $statusUrl = self::ASYNC_MATRIX_URL . '/' . rawurlencode($jobId);
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

            $statusResponse = $this->sendGet($statusUrl, [], ['key' => $this->config->apiKey]);

            if ($statusResponse->getStatusCode() >= 300) {
                $this->raiseHttpError($statusResponse, 'TomTom Matrix poll');
            }

            $status = $this->decodeJson($statusResponse);
            $state = (is_array($status) && isset($status['state']) && is_string($status['state']))
                ? $status['state']
                : null;

            if ($state === 'Succeeded') {
                return;
            }

            if ($state === 'Failed') {
                throw new ConnectorError(
                    statusCode: $statusResponse->getStatusCode(),
                    providerCode: ProviderCode::ProviderUnavailable,
                    providerMessage: 'TomTom Matrix job failed',
                    message: 'TomTom Matrix job failed',
                    cause: $status,
                );
            }

            // Otherwise continue polling on 'Pending' / 'Running' / etc.
        }

        throw new ConnectorError(
            statusCode: null,
            providerCode: ProviderCode::MatrixPollingTimeout,
            providerMessage: "jobId: {$jobId}",
            message: 'TomTom Matrix polling deadline exceeded',
            cause: ['jobId' => $jobId],
        );
    }

    /**
     * Step 3: retrieve the final async matrix payload (same `data[]` shape as
     * the sync response).
     */
    private function retrieveAsync(string $jobId): MatrixResult
    {
        $resultUrl = self::ASYNC_MATRIX_URL . '/' . rawurlencode($jobId) . '/result';

        $response = $this->sendGet($resultUrl, [], ['key' => $this->config->apiKey]);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response, 'TomTom Matrix retrieve');
        }

        $data = $this->decodeJson($response);
        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'TomTom Matrix retrieve returned non-JSON body',
                cause: $data,
            );
        }

        return $this->normalizeCells($data);
    }

    /**
     * Build the shared origins/destinations/options body + query (sync + async
     * use identical request shapes).
     *
     * @return array{0: array<string, mixed>, 1: array<string, string|int|float|bool>}
     */
    private function buildRequest(MatrixOptions $options): array
    {
        $bodyOptions = [
            'travelMode' => $this->mapTravelMode($options->travelMode),
        ];

        if ($options->avoidTolls) {
            $bodyOptions['avoid'] = ['tollRoads'];
        }

        if ($options->departureTime !== null) {
            $bodyOptions['departAt'] = $options->departureTime->format('c');
        }

        /** @var array<string, mixed> $body */
        $body = [
            'origins' => array_map(
                static fn(LatLng $o): array => ['point' => ['latitude' => $o->lat, 'longitude' => $o->lng]],
                $options->origins,
            ),
            'destinations' => array_map(
                static fn(LatLng $d): array => ['point' => ['latitude' => $d->lat, 'longitude' => $d->lng]],
                $options->destinations,
            ),
            'options' => $bodyOptions,
        ];

        /** @var array<string, string|int|float|bool> $query */
        $query = ['key' => $this->config->apiKey];

        return [$body, $query];
    }

    /**
     * Flatten the `data[]` array (shared between sync and async result shapes)
     * into `MatrixCell` instances. Cells without a `routeSummary` are skipped,
     * matching *
     * @param array<string, mixed> $data
     */
    private function normalizeCells(array $data): MatrixResult
    {
        $entries = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : [];

        $cells = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $summary = $entry['routeSummary'] ?? null;
            if (!is_array($summary)) {
                continue;
            }
            // Treat an entry with a routeSummary but absent/non-numeric indices
            // as malformed (skip) rather than fabricating a (0,0) cell that
            // would overwrite the real origin/destination zero cell.
            if (!self::isIntLike($entry['originIndex'] ?? null) || !self::isIntLike($entry['destinationIndex'] ?? null)) {
                continue;
            }
            $cells[] = new MatrixCell(
                originIndex: self::toInt($entry['originIndex']),
                destinationIndex: self::toInt($entry['destinationIndex']),
                distanceMeters: self::toFloat($summary['lengthInMeters'] ?? 0),
                durationSeconds: self::toFloat($summary['travelTimeInSeconds'] ?? 0),
            );
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
     * Map vendor HTTP status to {@see ProviderCode}. 17
     *. Locality: per-connector.
     *
     * @param mixed $body Decoded vendor error body (may be null/scalar/array).
     */
    private function mapVendorError(int $httpStatus, mixed $body): ProviderCode
    {
        unset($body); // TomTom classification is purely status-driven.

        if ($httpStatus === 401 || $httpStatus === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($httpStatus === 429) {
            return ProviderCode::RateLimited;
        }
        if ($httpStatus === 400 || $httpStatus === 404) {
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
            $msg = $body['message'] ?? null;
            if (is_string($msg) && $msg !== '') {
                $base = $msg;
            } elseif (isset($body['error'])) {
                $error = $body['error'];
                if (is_string($error) && $error !== '') {
                    $base = $error;
                } elseif (is_array($error)) {
                    $errMsg = $error['message'] ?? null;
                    $errDesc = $error['description'] ?? null;
                    if (is_string($errMsg) && $errMsg !== '') {
                        $base = $errMsg;
                    } elseif (is_string($errDesc) && $errDesc !== '') {
                        $base = $errDesc;
                    }
                }
            } elseif (isset($body['detailedError']) && is_array($body['detailedError'])) {
                $detail = $body['detailedError'];
                $detailMsg = $detail['message'] ?? null;
                if (is_string($detailMsg) && $detailMsg !== '') {
                    $base = $detailMsg;
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
     * Read + JSON-decode a response body. Returns null when empty or invalid.
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

    /**
     * Map {@see TravelMode} → TomTom Matrix v2 travel-mode name (mirrors TS).
     * v2 supports only `car` / `pedestrian` (no bicycle endpoint), so `cycling`
     * is rejected with `unsupported_travel_mode` rather than silently forwarded
     * as `bicycle` (which the v2 endpoint does not accept).
     */
    private function mapTravelMode(TravelMode $mode): string
    {
        return match ($mode) {
            TravelMode::Walking => 'pedestrian',
            TravelMode::Cycling => throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::UnsupportedTravelMode,
                providerMessage: 'TomTom Matrix v2 does not support cycling',
            ),
            TravelMode::Driving => 'car',
        };
    }

    private static function isIntLike(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
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

    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
