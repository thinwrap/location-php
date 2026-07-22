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
use Thinwrap\Location\Contract\RoutingConnectorInterface;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Routing\RoutingLeg;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\DTO\Routing\RoutingResult;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Util\Passthrough;
use Thinwrap\Location\Util\Polyline;

/**
 * TomTom Routing API v1 connector — PHP mirror of the TS connector
 *
 * GETs `https://api.tomtom.com/routing/1/calculateRoute/<coords>/json` with the
 * `key` query parameter for auth. Waypoints are colon-separated `lat,lng` pairs
 * embedded directly in the path. Avoidance toggles (tolls / ferries / highways)
 * collapse into the comma-separated `avoid` query value, and `optimize=true`
 * (with more than two waypoints) maps to `computeBestOrder=true`. The optimized
 * order, if present, lives at `data.optimizedWaypoints[]` and is sorted by
 * `optimizedIndex` before being projected to `providedIndex`.
 *
 * Geometry: TomTom returns each leg's polyline as a list of
 * `{ latitude, longitude }` JSON objects under `routes[0].legs[*].points`. They
 * are flattened across legs and re-encoded to a Google precision-5 polyline via
 * {@see Polyline::encodePolyline()}. Rate limiting is signalled via the
 * standard `Retry-After` header on 429 — surfaced by design (parsed seconds in `providerMessage`,
 * raw header at `cause.retryAfter`; no `retryAfterSeconds` field).
 *
 * dispatch + error classification live entirely inside this
 * connector (no shared translator middleware).
 */
final class TomTomRoutingConnector extends BaseConnector implements RoutingConnectorInterface
{
    private const ROUTE_URL = 'https://api.tomtom.com/routing/1/calculateRoute';

    public function __construct(
        private readonly TomTomConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        parent::__construct($httpClient, $requestFactory, $streamFactory);
    }

    public function getProviderId(): string
    {
        return LocationProviderId::TomTom->value;
    }

    public function route(RoutingOptions $options): RoutingResult
    {
        $waypoints = $options->waypoints;
        if (count($waypoints) < 2) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'TomTom Routing requires at least two waypoints',
            );
        }

        $locations = implode(':', array_map(
            static fn(LatLng $wp): string => $wp->toLatLngString(),
            $waypoints,
        ));

        $url = self::ROUTE_URL . "/{$locations}/json";

        /** @var array<string, string|int|float|bool> $query */
        $query = [
            'key'        => $this->config->apiKey,
            'travelMode' => $this->mapTravelMode($options->travelMode),
            'routeType'  => 'fastest',
        ];

        // TomTom computeBestOrder reorders intermediate waypoints while keeping the
        // first/last fixed (an OPEN route); it has no closed round-trip mode.
        if ($options->isRoundTrip) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::UnsupportedOption,
                providerMessage: 'TomTom computeBestOrder optimizes an open route (fixed first/last waypoint) and cannot return a closed round trip; remove isRoundTrip or use a provider that supports it (e.g. Mapbox/OSRM).',
            );
        }

        if ($options->optimize && count($waypoints) > 2) {
            $query['computeBestOrder'] = 'true';
        }

        if ($options->departureTime !== null) {
            $query['departAt'] = $options->departureTime->format('c');
        }

        $avoid = $this->buildAvoid($options);
        if ($avoid !== '') {
            $query['avoid'] = $avoid;
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options));

        $response = $this->sendGet($url, $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response);
        }

        $data = $this->decodeJson($response);

        $routes = (is_array($data) && isset($data['routes']) && is_array($data['routes']))
            ? $data['routes']
            : [];
        $route = $routes[0] ?? null;
        if (!is_array($route)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'TomTom Routing returned no routes',
                cause: $data,
            );
        }

        $legsRaw = (isset($route['legs']) && is_array($route['legs'])) ? $route['legs'] : [];

        /** @var list<RoutingLeg> $legs */
        $legs = [];
        /** @var list<LatLng> $allPoints */
        $allPoints = [];
        foreach ($legsRaw as $leg) {
            if (!is_array($leg)) {
                continue;
            }
            $summary = (isset($leg['summary']) && is_array($leg['summary'])) ? $leg['summary'] : [];
            $legs[] = new RoutingLeg(
                distanceMeters: self::toFloat($summary['lengthInMeters'] ?? 0),
                durationSeconds: self::toFloat($summary['travelTimeInSeconds'] ?? 0),
            );

            $points = (isset($leg['points']) && is_array($leg['points'])) ? $leg['points'] : [];
            foreach ($points as $p) {
                if (!is_array($p) || !isset($p['latitude'], $p['longitude'])) {
                    continue;
                }
                $allPoints[] = new LatLng(self::toFloat($p['latitude']), self::toFloat($p['longitude']));
            }
        }

        $polyline = Polyline::encodePolyline($allPoints);

        $summary = (isset($route['summary']) && is_array($route['summary'])) ? $route['summary'] : [];
        $totalDistance = self::toFloat($summary['lengthInMeters'] ?? 0);
        $totalDuration = self::toFloat($summary['travelTimeInSeconds'] ?? 0);

        $waypointOrder = null;
        if ($options->optimize && isset($data['optimizedWaypoints']) && is_array($data['optimizedWaypoints'])) {
            $waypointOrder = $this->extractWaypointOrder($data['optimizedWaypoints'], count($options->waypoints));
        }

        return new RoutingResult(
            legs: $legs,
            totalDistanceMeters: $totalDistance,
            totalDurationSeconds: $totalDuration,
            polyline: $polyline,
            waypointOrder: $waypointOrder,
            raw: is_array($data) ? $data : null,
        );
    }

    /**
     * Project TomTom's intermediate-relative `optimizedWaypoints` onto the full
     * input-index visiting sequence. `optimizedWaypoints` covers ONLY the
     * intermediate waypoints (`providedIndex` 0-based over intermediates,
     * origin and destination excluded); the canonical `waypointOrder` brackets
     * the projected intermediates with the fixed origin (0) and
     * destination (`$n - 1`).
     *
     * @param list<mixed>|array<int|string, mixed> $optimized
     * @return list<int>
     */
    private function extractWaypointOrder(array $optimized, int $n): array
    {
        /** @var list<array{providedIndex: int, optimizedIndex: int}> $entries */
        $entries = [];
        foreach ($optimized as $wp) {
            if (!is_array($wp)) {
                continue;
            }
            $provided = $wp['providedIndex'] ?? null;
            $opt = $wp['optimizedIndex'] ?? null;
            if (!self::isIntLike($provided) || !self::isIntLike($opt)) {
                continue;
            }
            $entries[] = [
                'providedIndex'  => self::toInt($provided),
                'optimizedIndex' => self::toInt($opt),
            ];
        }

        usort(
            $entries,
            /**
             * @param array{providedIndex: int, optimizedIndex: int} $a
             * @param array{providedIndex: int, optimizedIndex: int} $b
             */
            static fn(array $a, array $b): int => $a['optimizedIndex'] <=> $b['optimizedIndex'],
        );

        $intermediates = array_map(
            /**
             * @param array{providedIndex: int, optimizedIndex: int} $entry
             */
            static fn(array $entry): int => $entry['providedIndex'] + 1,
            $entries,
        );

        return array_merge([0], $intermediates, [$n - 1]);
    }

    private function buildAvoid(RoutingOptions $options): string
    {
        $avoids = [];
        if ($options->avoidTolls) {
            $avoids[] = 'tollRoads';
        }
        if ($options->avoidFerries) {
            $avoids[] = 'ferries';
        }
        if ($options->avoidHighways) {
            $avoids[] = 'motorways';
        }

        return implode(',', $avoids);
    }

    /**
     * Map vendor HTTP status + body shape to {@see ProviderCode}. Mirrors TS
     * Locality: per-connector.
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
            message: "TomTom Routing failed: {$status}",
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
    private function buildPassthroughBuckets(RoutingOptions $options): ?array
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
            TravelMode::Walking => 'pedestrian',
            TravelMode::Cycling => 'bicycle',
            TravelMode::Driving => 'car',
        };
    }

    private static function isIntLike(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) || is_float($value);
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
