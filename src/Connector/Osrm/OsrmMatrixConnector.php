<?php

declare(strict_types=1);

namespace Thinwrap\Location\Connector\Osrm;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Location\Base\BaseConnector;
use Thinwrap\Location\Config\OsrmConfig;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\Contract\MatrixConnectorInterface;
use Thinwrap\Location\DTO\Matrix\MatrixCell;
use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\DTO\Matrix\MatrixResult;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Util\Coordinate;
use Thinwrap\Location\Util\Passthrough;

/**
 * OSRM Matrix connector — PHP mirror of the TS connector
 *
 *   `GET <baseUrl>/table/v1/{profile}/{coords}?annotations=duration,distance&sources=...&destinations=...`
 *
 * Mirrors the architectural shape of {@see OsrmRoutingConnector}:
 *
 *   1. **Required `baseUrl`** — validated synchronously in the constructor; no
 * public-demo default. Consumers self-host OSRM so there is no
 *      auth handling at the connector level.
 *
 * 2. **Pre-flight validation** — raises typed {@see ConnectorError}
 * with the location-extended {@see ProviderCode} values BEFORE any HTTP
 * call. `IMatrixOptions` carries only `departureTime` + `avoidTolls`
 * (Routing-only avoid flags are absent from the locked Matrix shape),
 * so the matrix pre-flight is simpler than Routing's — only 2 checks.
 *
 *   3. **`annotations=duration,distance` invariant** — re-applied after
 *      {@see Passthrough::merge()} so consumer overrides are silently
 * overwritten. Mirrors {@see MapboxMatrixConnector}
 *
 *   4. **In-body code mapping** — OSRM responds 200-OK with `code != 'Ok'`
 *      for logical errors. `NoTable`/`InvalidQuery`/`NoSegment`/`TooBig`
 * map to `invalid_request`.
 *
 * `mapVendorError` is minimal: vanilla OSRM has no auth and no rate
 * limits, but reverse proxies may add them — surface 401/403/429 generically.
 * Retry-After surfaces as parsed seconds inside `providerMessage` + raw header
 * value inside `cause.retryAfter` by design — no structured field.
 *
 * Travel-mode mapping uses the OSRM-standard profile names
 * `driving | walking | cycling`, mirroring {@see OsrmRoutingConnector} per
 * Consumer OSRM builds may compile alternate profiles —
 * see the per-connector README for verification guidance.
 */
final class OsrmMatrixConnector extends BaseConnector implements MatrixConnectorInterface
{
    public function __construct(
        private readonly OsrmConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        if ($this->config->baseUrl === '') {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'baseUrl is required for OSRM',
                message: 'OSRM connector requires explicit baseUrl. The public demo server is not used as a default.',
            );
        }

        parent::__construct($httpClient, $requestFactory, $streamFactory);
    }

    public function getProviderId(): string
    {
        return LocationProviderId::Osrm->value;
    }

    public function matrix(MatrixOptions $options): MatrixResult
    {
        // Pre-flight validation — raised before any HTTP work.
        $this->validateOsrmCompat($options);

        $origins = $options->origins;
        $destinations = $options->destinations;
        $originCount = count($origins);
        $destinationCount = count($destinations);

        if ($originCount === 0 || $destinationCount === 0) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'OSRM Matrix requires at least one origin and one destination',
            );
        }

        $profile = $this->mapProfile($options->travelMode);
        $allCoords = array_merge($origins, $destinations);
        $coords = Coordinate::joinLngLat($allCoords, ';');

        $sources = implode(';', range(0, $originCount - 1));
        $destinationsParam = implode(';', range($originCount, $originCount + $destinationCount - 1));

        $url = rtrim($this->config->baseUrl, '/') . "/table/v1/{$profile}/{$coords}";

        /** @var array<string, string|int|float|bool> $query */
        $query = [
            'sources' => $sources,
            'destinations' => $destinationsParam,
        ];

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options));

        // invariant: layer `annotations` in AFTER the passthrough merge so
        // a consumer attempt to override it is silently overwritten. Per
        // baseline-coverage discipline
        // the duration+distance contract is non-negotiable per request.
        $mergedQuery = $merged['query'];
        $mergedQuery['annotations'] = 'duration,distance';

        $response = $this->sendGet($url, $merged['headers'], $mergedQuery);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response);
        }

        $data = $this->decodeJson($response);

        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'OSRM Matrix returned non-JSON body',
                cause: $data,
            );
        }

        $code = isset($data['code']) && is_string($data['code']) ? $data['code'] : '';
        if ($code !== 'Ok') {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: $this->mapBodyCode($code),
                providerMessage: $this->formatBodyMessage($data, $code),
                cause: $data,
            );
        }

        $durations = (isset($data['durations']) && is_array($data['durations'])) ? $data['durations'] : [];
        $distances = (isset($data['distances']) && is_array($data['distances'])) ? $data['distances'] : [];

        // 2D → cells flatten, origin-major (matches byte-for-byte).
        $cells = [];
        for ($oi = 0; $oi < $originCount; $oi++) {
            $durationRow = is_array($durations[$oi] ?? null) ? $durations[$oi] : [];
            $distanceRow = is_array($distances[$oi] ?? null) ? $distances[$oi] : [];

            for ($di = 0; $di < $destinationCount; $di++) {
                $distance = $distanceRow[$di] ?? null;
                $duration = $durationRow[$di] ?? null;
                // OSRM `/table` returns `null` for an unroutable pair. Omit the
                // cell rather than coercing to 0 (which reads as "same
                // location"). Contract: missing/failed entries are omitted.
                if ($distance === null || $duration === null) {
                    continue;
                }
                $cells[] = new MatrixCell(
                    originIndex: $oi,
                    destinationIndex: $di,
                    distanceMeters: self::toFloat($distance),
                    durationSeconds: self::toFloat($duration),
                );
            }
        }

        return new MatrixResult(cells: $cells, raw: $data);
    }

    /**
     * Pre-flight validation. `IMatrixOptions` only
     * exposes `departureTime` and `avoidTolls` (no `avoidFerries`/`avoidHighways`
     *'s locked Matrix shape), so the matrix pre-flight is
     * simpler than Routing's — 2 checks vs Routing's 3 avoid flags.
     */
    private function validateOsrmCompat(MatrixOptions $options): void
    {
        if ($options->departureTime !== null) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::UnsupportedField,
                providerMessage: 'OSRM does not support departureTime',
            );
        }

        if ($options->avoidTolls === true) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::UnsupportedOption,
                providerMessage: 'avoidTolls is not supported by OSRM',
            );
        }
    }

    /**
     * Map OSRM in-body `code` values (returned even on HTTP 200) to
     * {@see ProviderCode}. `NoTable` and `InvalidQuery`
     * → `invalid_request`. Other logical-error codes follow the same shape
     * as {@see OsrmRoutingConnector::mapBodyCode()}.
     */
    private function mapBodyCode(string $code): ProviderCode
    {
        return match ($code) {
            'NoTable', 'NoSegment', 'InvalidQuery', 'InvalidOptions', 'InvalidValue', 'TooBig' => ProviderCode::InvalidRequest,
            default => ProviderCode::Unknown,
        };
    }

    /**
     * Map vendor HTTP-level status codes to {@see ProviderCode}. * minimal — 404 → `invalid_request`, 5xx → `provider_unavailable`.
     * Vanilla OSRM has no auth and no rate limits, but reverse proxies in
     * front of the instance may add 401/403/429 — surface those generically
     * (mirrors {@see OsrmRoutingConnector::mapVendorError()}).
     *
     * @param mixed $body Decoded vendor error body (may be null/scalar/array).
     */
    private function mapVendorError(int $httpStatus, mixed $body): ProviderCode
    {
        unset($body); // OSRM HTTP-level classification is status-driven.

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
     * Build the human-readable `providerMessage` from a 200-OK envelope that
     * carries `code != 'Ok'`. Includes the body `message` when present, else
     * falls back to the bare code.
     *
     * @param array<string, mixed> $data Decoded 200-OK body.
     */
    private function formatBodyMessage(array $data, string $code): string
    {
        $msg = $data['message'] ?? null;
        if (is_string($msg) && $msg !== '') {
            return "OSRM returned code: {$code}: {$msg}";
        }

        return 'OSRM returned code: ' . ($code !== '' ? $code : 'unknown');
    }

    /**
     * Build the human-readable `providerMessage` from a non-2xx HTTP body,
     * weaving in parsed Retry-After seconds when present by design.
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
                $err = $body['error'];
                if (is_string($err) && $err !== '') {
                    $base = $err;
                }
            } elseif (isset($body['code']) && is_string($body['code']) && $body['code'] !== '') {
                $base = $body['code'];
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
    private function raiseHttpError(ResponseInterface $response): never
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
            message: "OSRM Matrix failed: {$status}",
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
     * Map {@see TravelMode} to OSRM profile name, mirroring
     * {@see OsrmRoutingConnector::mapProfile()}.
     * OSRM-standard profile names are `driving | walking | cycling`; consumer
     * OSRM builds may compile alternate profiles — see the per-connector
     * README for verification guidance.
     */
    private function mapProfile(TravelMode $mode): string
    {
        return match ($mode) {
            TravelMode::Walking => 'walking',
            TravelMode::Cycling => 'cycling',
            TravelMode::Driving => 'driving',
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
