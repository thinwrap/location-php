<?php

declare(strict_types=1);

namespace Thinwrap\Location\Connector\Mapbox;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Location\Base\BaseConnector;
use Thinwrap\Location\Config\MapboxConfig;
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
 * Mapbox Matrix connector — PHP mirror of the TS connector
 *
 *   `GET /directions-matrix/v1/mapbox/{profile}/{coords}`
 *
 * Wire parity: coords joined `lng,lat;lng,lat`; `sources` indexes the
 * first `count($origins)` entries, `destinations` indexes the remainder.
 *
 * `annotations=duration,distance` is an **invariant**: it is layered in
 * *after* {@see Passthrough::merge()} so a consumer-supplied passthrough query
 * value for the same key is silently overwritten. This matches the canonical
 * baseline-coverage discipline — `annotations` is the contract Thinwrap exposes
 * to consumers and is not negotiable per-request.
 *
 * 2D → cells flattening walks rows-first (origin-major) so cell order
 * matches the TS connector byte-for-byte.
 *
 * `mapVendorError` mirrors the / shared-Mapbox shape:
 * 401/403 → `AuthFailed`, 429 → `RateLimited`, 422 → `InvalidRequest`,
 * 5xx → `ProviderUnavailable`, fallback → `Unknown`. Retry-After surfaces as
 * parsed seconds inside `providerMessage` plus the raw header value inside
 * `cause.retryAfter` (no structured field by design).
 */
final class MapboxMatrixConnector extends BaseConnector implements MatrixConnectorInterface
{
    private const MATRIX_URL = 'https://api.mapbox.com/directions-matrix/v1/mapbox';

    public function __construct(
        private readonly MapboxConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        parent::__construct($httpClient, $requestFactory, $streamFactory);
    }

    public function getProviderId(): string
    {
        return LocationProviderId::Mapbox->value;
    }

    public function matrix(MatrixOptions $options): MatrixResult
    {
        $origins = $options->origins;
        $destinations = $options->destinations;
        $originCount = count($origins);
        $destinationCount = count($destinations);

        if ($originCount === 0 || $destinationCount === 0) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Mapbox Matrix requires at least one origin and one destination',
            );
        }

        $profile = $this->mapProfile($options->travelMode);
        $allCoords = array_merge($origins, $destinations);
        $coords = Coordinate::joinLngLat($allCoords, ';');

        $sources = implode(';', range(0, $originCount - 1));
        $destinationsParam = implode(';', range($originCount, $originCount + $destinationCount - 1));

        $url = self::MATRIX_URL . "/{$profile}/{$coords}";

        $query = [
            'access_token' => $this->config->accessToken,
            'sources' => $sources,
            'destinations' => $destinationsParam,
        ];

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options));

        // invariant: re-apply `annotations` *after* the passthrough merge so
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
                providerMessage: 'Mapbox Matrix returned non-JSON body',
                cause: $data,
            );
        }

        $code = isset($data['code']) && is_string($data['code']) ? $data['code'] : '';
        if ($code !== 'Ok') {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: $this->mapBodyCode($code),
                providerMessage: 'Mapbox returned code: ' . ($code !== '' ? $code : 'unknown'),
                cause: $data,
            );
        }

        $durations = (isset($data['durations']) && is_array($data['durations'])) ? $data['durations'] : [];
        $distances = (isset($data['distances']) && is_array($data['distances'])) ? $data['distances'] : [];

        // 2D → cells flatten, origin-major (matches TS connector exactly).
        $cells = [];
        for ($oi = 0; $oi < $originCount; $oi++) {
            $durationRow = is_array($durations[$oi] ?? null) ? $durations[$oi] : [];
            $distanceRow = is_array($distances[$oi] ?? null) ? $distances[$oi] : [];

            for ($di = 0; $di < $destinationCount; $di++) {
                $distance = $distanceRow[$di] ?? null;
                $duration = $durationRow[$di] ?? null;
                // Mapbox returns `null` for an unroutable pair. Omit the cell
                // rather than coercing to 0 (which reads as "same location").
                // Contract: missing/failed entries are omitted from cells.
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
     * Map vendor HTTP status + body shape to {@see ProviderCode}. Mirrors TS
     * Locality: per-connector.
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
        if ($httpStatus === 400 || $httpStatus === 422) {
            return ProviderCode::InvalidRequest;
        }
        if ($httpStatus >= 500 && $httpStatus < 600) {
            return ProviderCode::ProviderUnavailable;
        }

        return ProviderCode::Unknown;
    }

    /**
     * Map a 200-OK envelope error code (`data.code != 'Ok'`) to ProviderCode.
     */
    private function mapBodyCode(string $code): ProviderCode
    {
        return match ($code) {
            'NoRoute', 'NoSegment', 'InvalidInput', 'ProfileNotFound' => ProviderCode::InvalidRequest,
            'ProcessingError' => ProviderCode::Unknown,
            default => ProviderCode::Unknown,
        };
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
            message: "Mapbox Matrix failed: {$status}",
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
