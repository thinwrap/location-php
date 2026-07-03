<?php

declare(strict_types=1);

namespace Thinwrap\Location\Connector\Google;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Location\Base\BaseConnector;
use Thinwrap\Location\Config\GoogleConfig;
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
 * Google RouteMatrix v2 connector — PHP mirror of the TS connector
 *
 * Posts to https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix
 * with the same `X-Goog-Api-Key` / `X-Goog-FieldMask` headers and the same
 * Route-Matrix v2 request body as Google emits an NDJSON-shaped
 * stream (one JSON object per `(originIndex, destinationIndex)` pair); this
 * connector reads the full PSR-7 body via `(string) $response->getBody()`,
 * splits on `\n`, parses each line, skips failed-status elements, and maps
 * successful elements into `cells[]`. Failed elements are kept in `raw`.
 *
 * Body shape, error-mapping table and Retry-After surfacing follow the
 * Story-3.6 per-connector template established by {@see GoogleRoutingConnector}.
 */
final class GoogleMatrixConnector extends BaseConnector implements MatrixConnectorInterface
{
    private const MATRIX_URL = 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix';

    public function __construct(
        private readonly GoogleConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        parent::__construct($httpClient, $requestFactory, $streamFactory);
    }

    public function getProviderId(): string
    {
        return LocationProviderId::Google->value;
    }

    public function matrix(MatrixOptions $options): MatrixResult
    {
        $origins = [];
        foreach ($options->origins as $o) {
            $origins[] = ['waypoint' => ['location' => ['latLng' => ['latitude' => $o->lat, 'longitude' => $o->lng]]]];
        }

        $destinations = [];
        foreach ($options->destinations as $d) {
            $destinations[] = ['waypoint' => ['location' => ['latLng' => ['latitude' => $d->lat, 'longitude' => $d->lng]]]];
        }

        /** @var array<string, mixed> $body */
        $body = [
            'origins' => $origins,
            'destinations' => $destinations,
            'travelMode' => $this->mapTravelMode($options->travelMode),
            'routingPreference' => $options->departureTime !== null ? 'TRAFFIC_AWARE' : 'TRAFFIC_UNAWARE',
        ];

        if ($options->avoidTolls) {
            $body['routeModifiers'] = ['avoidTolls' => true];
        }

        if ($options->departureTime !== null) {
            $body['departureTime'] = $options->departureTime->format('c');
        }

        /** @var array<string, string> $headers */
        $headers = [
            'X-Goog-Api-Key' => $this->config->apiKey,
            'X-Goog-FieldMask' => 'originIndex,destinationIndex,distanceMeters,duration,status',
        ];

        $merged = Passthrough::merge($body, $headers, [], $this->buildPassthroughBuckets($options));

        $response = $this->sendPostJson(self::MATRIX_URL, $merged['body'], $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response);
        }

        $raw = (string) $response->getBody();
        $elements = self::parseNdjson($raw);

        $cells = [];
        foreach ($elements as $el) {
            if (!self::elementOk($el)) {
                continue;
            }
            // A successful element with absent/non-numeric origin or destination
            // index is malformed; skip it rather than fabricating a (0,0) cell.
            $originIndex = self::toNullableInt($el['originIndex'] ?? null);
            $destinationIndex = self::toNullableInt($el['destinationIndex'] ?? null);
            if ($originIndex === null || $destinationIndex === null) {
                continue;
            }
            $cells[] = new MatrixCell(
                originIndex: $originIndex,
                destinationIndex: $destinationIndex,
                distanceMeters: self::toFloat($el['distanceMeters'] ?? 0),
                durationSeconds: (float) self::parseDuration($el['duration'] ?? '0s'),
            );
        }

        return new MatrixResult(cells: $cells, raw: $elements);
    }

    /**
     * Map vendor HTTP status + body shape to {@see ProviderCode}. Mirrors TS
     * (which delegates to the table).
     *
     * @param mixed $body Decoded vendor error body (may be null/scalar/array).
     */
    private function mapVendorError(int $httpStatus, mixed $body): ProviderCode
    {
        $googleStatus = null;
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $error = $body['error'];
            if (isset($error['status']) && is_string($error['status'])) {
                $googleStatus = $error['status'];
            }
        }

        if ($httpStatus === 401) {
            return ProviderCode::AuthFailed;
        }
        if ($httpStatus === 403) {
            if ($googleStatus === 'QUOTA_EXCEEDED') {
                return ProviderCode::RateLimited;
            }

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
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $msg = $body['error']['message'] ?? null;
            if (is_string($msg) && $msg !== '') {
                $base = $msg;
            }
        }

        if ($retryAfter !== null && $retryAfter !== '') {
            if (is_numeric($retryAfter)) {
                $seconds = (int) $retryAfter;
                $suffix = "retry after {$seconds} seconds";

                return $base !== null ? "{$base}; {$suffix}" : $suffix;
            }
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
            message: "Google Matrix failed: {$status}",
            cause: $cause,
        );
    }

    /**
     * Read + JSON-decode an error-response body. Returns null when the body is
     * empty or not valid JSON (mirrors the TS `.catch(() => null)` shape used by
     * the Routing connector). Used only for non-2xx error paths; the happy-path
     * body is NDJSON and parsed via {@see parseNdjson()}.
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
     * Parse the Google RouteMatrix NDJSON-shaped body.
     *
     * Google's RouteMatrix v2 emits one JSON object per `(originIndex,
     * destinationIndex)` pair, separated by newlines (not array-wrapped). To
     * stay liberal about formatting we also accept the array-wrapped shape
     * (some test fixtures and proxy buffers re-wrap the stream), and lines that
     * decode to a list of element objects (a single chunk containing multiple
     * elements). Lines that fail to JSON-parse are skipped.
     *
     * @return list<array<string, mixed>>
     */
    private static function parseNdjson(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        // Tolerate the array-wrapped shape (a single JSON array body).
        if ($trimmed[0] === '[') {
            try {
                $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
            if (is_array($decoded)) {
                $elements = [];
                foreach ($decoded as $item) {
                    if (is_array($item)) {
                        /** @var array<string, mixed> $item */
                        $elements[] = $item;
                    }
                }

                return $elements;
            }
        }

        $elements = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                // A single malformed NDJSON line must not be silently discarded
                // (that would drop the whole response). Normalize to the unified
                // ConnectorError contract, mirroring the TS sibling.
                throw new ConnectorError(
                    statusCode: null,
                    providerCode: ProviderCode::Unknown,
                    providerMessage: 'Malformed matrix response line',
                    cause: $e,
                );
            }
            if (is_array($decoded)) {
                // A single chunk may itself be an array of elements.
                if (array_is_list($decoded)) {
                    foreach ($decoded as $item) {
                        if (is_array($item)) {
                            /** @var array<string, mixed> $item */
                            $elements[] = $item;
                        }
                    }
                } else {
                    /** @var array<string, mixed> $decoded */
                    $elements[] = $decoded;
                }
            }
        }

        return $elements;
    }

    /**
     * Predicate: keep an element only when Google reports a successful status.
     * Google encodes per-element failures via `status.code !== 0` (gRPC-style).
     * Elements with no status at all are treated as successful (the Routes API
     * historically omits the `status` field on healthy cells).
     *
     * @param array<string, mixed> $el
     */
    private static function elementOk(array $el): bool
    {
        if (!isset($el['status'])) {
            return true;
        }
        $status = $el['status'];
        if (!is_array($status)) {
            return true;
        }
        if (isset($status['code'])) {
            $code = $status['code'];
            if (is_int($code)) {
                return $code === 0;
            }
            if (is_numeric($code)) {
                return (int) $code === 0;
            }
        }

        return true;
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

    private function mapTravelMode(TravelMode $mode): string
    {
        return match ($mode) {
            TravelMode::Walking => 'WALK',
            TravelMode::Cycling => 'BICYCLE',
            TravelMode::Driving => 'DRIVE',
        };
    }

    private static function parseDuration(mixed $duration): int
    {
        if (is_string($duration)) {
            return (int) str_replace('s', '', $duration);
        }
        if (is_int($duration)) {
            return $duration;
        }
        if (is_float($duration)) {
            return (int) $duration;
        }

        return 0;
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

    /**
     * Parse an integer index, returning null (rather than 0) for an absent or
     * non-numeric value, so callers can skip malformed elements instead of
     * fabricating a (0,0)-indexed cell.
     */
    private static function toNullableInt(mixed $value): ?int
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

        return null;
    }
}
