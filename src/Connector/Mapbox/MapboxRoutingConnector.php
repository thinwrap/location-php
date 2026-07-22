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
use Thinwrap\Location\Contract\RoutingConnectorInterface;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Routing\RoutingLeg;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\DTO\Routing\RoutingResult;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Util\Coordinate;
use Thinwrap\Location\Util\Passthrough;
use Thinwrap\Location\Util\Polyline;

/**
 * Mapbox routing connector — PHP mirror of the TS connector (the architectural
 * outlier). Dispatches between two endpoints based on the optimization flags:
 *
 *   - `GET /directions/v5/mapbox/{profile}/{coords}` for plain routing.
 *   - `GET /optimized-trips/v1/mapbox/{profile}/{coords}` for waypoint-order
 *     optimization (single-vehicle TSP) when any of
 *     `optimize | optimizeFixedOrigin | optimizeFixedDestination | isRoundTrip`
 *     is set. (v1 is the single-route optimizer matching every sibling provider;
 *     v2 is a fleet/VRP product for a future multi-vehicle surface.)
 *
 * Mapbox returns precision-6 polyline geometry by default (we ask for
 * `geometries=polyline6`). The result is decoded with a connector-private
 * precision-6 decoder ({@see decodePolyline6()}) and re-encoded via
 * {@see Polyline::encodePolyline()} to match the canonical precision-5 polyline
 * shape that all thinwrap location connectors return.
 *
 * the dispatch logic lives entirely inside this connector — no
 * shared translator middleware. The precision-6 decoder is intentionally local
 * (the public {@see Polyline} surface stays at the 4 functions locked by Story
 * 3.4 / 1.4).
 */
final class MapboxRoutingConnector extends BaseConnector implements RoutingConnectorInterface
{
    private const DIRECTIONS_URL = 'https://api.mapbox.com/directions/v5/mapbox';
    private const OPTIMIZED_TRIPS_URL = 'https://api.mapbox.com/optimized-trips/v1/mapbox';

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

    public function route(RoutingOptions $options): RoutingResult
    {
        $waypoints = $options->waypoints;
        if (count($waypoints) < 2) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Mapbox Routing requires at least two waypoints',
            );
        }

        $profile = $this->mapProfile($options->travelMode);

        // TS-identical dispatch trigger restored. Any
        // optimization flag routes through `/optimized-trips`. Safe now that
        // `optimizeFixedOrigin` / `optimizeFixedDestination` default to `false`
        // (the DTO default change is the single root fix; the trigger matches TS).
        $useOptimized = $options->optimize
            || $options->optimizeFixedOrigin
            || $options->optimizeFixedDestination
            || $options->isRoundTrip;

        $response = $useOptimized
            ? $this->dispatchOptimized($options, $profile)
            : $this->dispatchDirections($options, $profile);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response);
        }

        $data = $this->decodeJson($response);

        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'Mapbox Routing returned non-JSON body',
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

        $routes = (isset($data['routes']) && is_array($data['routes'])) ? $data['routes'] : [];
        // `/optimized-trips/v1` returns the routes under `trips`.
        if ($routes === [] && isset($data['trips']) && is_array($data['trips'])) {
            $routes = $data['trips'];
        }
        $route = $routes[0] ?? null;
        if (!is_array($route)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'Mapbox Routing returned no routes',
                cause: $data,
            );
        }

        $legsRaw = (isset($route['legs']) && is_array($route['legs'])) ? $route['legs'] : [];
        $legs = [];
        foreach ($legsRaw as $leg) {
            if (!is_array($leg)) {
                continue;
            }
            $legs[] = new RoutingLeg(
                distanceMeters: self::toFloat($leg['distance'] ?? 0),
                durationSeconds: self::toFloat($leg['duration'] ?? 0),
            );
        }

        $polyline = '';
        if (isset($route['geometry']) && is_string($route['geometry']) && $route['geometry'] !== '') {
            $polyline = Polyline::encodePolyline(self::decodePolyline6($route['geometry']));
        }

        $waypointOrder = null;
        if ($useOptimized && isset($data['waypoints']) && is_array($data['waypoints'])) {
            // Canonical `waypointOrder` = full visiting sequence of INPUT indices
            // (origin/destination inclusive). Mapbox returns `waypoints[]` in INPUT
            // order, where each `waypoint_index` is the position that input
            // waypoint occupies in the optimized trip — i.e. the INVERSE of the
            // canonical. Invert it: place each input index at its visit position.
            $waypointOrder = self::invertWaypointIndices(array_values($data['waypoints']));
        }

        return new RoutingResult(
            legs: $legs,
            totalDistanceMeters: self::toFloat($route['distance'] ?? 0),
            totalDurationSeconds: self::toFloat($route['duration'] ?? 0),
            polyline: $polyline,
            waypointOrder: $waypointOrder,
            raw: $data,
        );
    }

    /**
     * Plain `/directions/v5` GET dispatch.
     */
    private function dispatchDirections(RoutingOptions $options, string $profile): ResponseInterface
    {
        $coords = Coordinate::joinLngLat($options->waypoints, ';');
        $url = self::DIRECTIONS_URL . "/{$profile}/{$coords}";

        $query = [
            'access_token' => $this->config->accessToken,
            'geometries' => 'polyline6',
            'overview' => 'full',
            'steps' => 'true',
            'annotations' => 'duration,distance',
        ];

        $excludes = $this->buildExcludes($options);
        if ($excludes !== '') {
            $query['exclude'] = $excludes;
        }

        if ($options->departureTime !== null) {
            $query['depart_at'] = $options->departureTime->format('c');
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options));

        return $this->sendGet($url, $merged['headers'], $merged['query']);
    }

    /**
     * `/optimized-trips/v1` GET dispatch (waypoint-order optimization; the
     * single-vehicle optimizer that matches every sibling provider — v2 is a
     * fleet/VRP product for a future multi-vehicle surface).
     *
     * v1 (OSRM-trip-based) rejects source=any + destination=any + roundtrip=false,
     * so plain `optimize` (and the both-fixed case) keeps BOTH endpoints and
     * reorders the intermediates, matching Google/TomTom/HERE/Esri; the
     * fixed-origin/-destination flags pin just their endpoint and free the other;
     * `isRoundTrip` returns to the first waypoint.
     */
    private function dispatchOptimized(RoutingOptions $options, string $profile): ResponseInterface
    {
        $coords = Coordinate::joinLngLat($options->waypoints, ';');
        $url = self::OPTIMIZED_TRIPS_URL . "/{$profile}/{$coords}";

        $query = [
            'access_token' => $this->config->accessToken,
            'geometries' => 'polyline6',
            'overview' => 'full',
            'steps' => 'true',
            'annotations' => 'duration,distance',
            'roundtrip' => $options->isRoundTrip ? 'true' : 'false',
        ];

        if ($options->isRoundTrip) {
            $query['source'] = 'first';
        } elseif ($options->optimizeFixedOrigin && !$options->optimizeFixedDestination) {
            $query['source'] = 'first';
            $query['destination'] = 'any';
        } elseif ($options->optimizeFixedDestination && !$options->optimizeFixedOrigin) {
            $query['source'] = 'any';
            $query['destination'] = 'last';
        } else {
            // Plain `optimize`, or both endpoints fixed: keep origin first and
            // destination last, reorder the middle (the only any/any alternative
            // v1 accepts for a non-roundtrip request).
            $query['source'] = 'first';
            $query['destination'] = 'last';
        }

        $excludes = $this->buildExcludes($options);
        if ($excludes !== '') {
            $query['exclude'] = $excludes;
        }

        if ($options->departureTime !== null) {
            $query['depart_at'] = $options->departureTime->format('c');
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options));

        return $this->sendGet($url, $merged['headers'], $merged['query']);
    }

    private function buildExcludes(RoutingOptions $options): string
    {
        $excludes = [];
        if ($options->avoidTolls) {
            $excludes[] = 'toll';
        }
        if ($options->avoidFerries) {
            $excludes[] = 'ferry';
        }
        if ($options->avoidHighways) {
            $excludes[] = 'motorway';
        }

        return implode(',', $excludes);
    }

    /**
     * Map vendor HTTP status + body shape to {@see ProviderCode}. Mirrors TS
     * Locality: per-connector.
     *
     * @param mixed $body Decoded vendor error body (may be null/scalar/array).
     */
    private function mapVendorError(int $httpStatus, mixed $body): ProviderCode
    {
        $code = null;
        if (is_array($body) && isset($body['code']) && is_string($body['code'])) {
            $code = $body['code'];
        }

        if ($httpStatus === 401 || $httpStatus === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($httpStatus === 429) {
            return ProviderCode::RateLimited;
        }
        if ($httpStatus === 422) {
            if ($code === 'NoRoute' || $code === 'NoTrips') {
                return ProviderCode::InvalidRequest;
            }

            return ProviderCode::Unknown;
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
     * Map a 200-OK envelope error code (`data.code != 'Ok'`) to ProviderCode.
     * Mapbox sometimes returns 200 with `code: NoRoute` / `code: NoSegment` etc.
     */
    private function mapBodyCode(string $code): ProviderCode
    {
        return match ($code) {
            'NoRoute', 'NoTrips', 'NoSegment', 'InvalidInput' => ProviderCode::InvalidRequest,
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
            message: "Mapbox Routing failed: {$status}",
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

    private function mapProfile(TravelMode $mode): string
    {
        return match ($mode) {
            TravelMode::Walking => 'walking',
            TravelMode::Cycling => 'cycling',
            TravelMode::Driving => 'driving',
        };
    }

    /**
     * Connector-private precision-6 polyline decoder. 7's
     * inline `decodePrecision6` helper — a 1e6-divisor variant of the standard
     * precision-5 decoder. Deliberately not added to the public {@see Polyline}
     * surface (locked it at 4 functions).
     *
     * @return list<LatLng>
     */
    private static function decodePolyline6(string $encoded): array
    {
        $coords = [];
        $index = 0;
        $lat = 0;
        $lng = 0;
        $length = strlen($encoded);

        while ($index < $length) {
            [$latDelta, $index] = self::decodeSignedValue($encoded, $index);
            $lat += $latDelta;

            [$lngDelta, $index] = self::decodeSignedValue($encoded, $index);
            $lng += $lngDelta;

            $coords[] = new LatLng($lat / 1e6, $lng / 1e6);
        }

        return $coords;
    }

    /**
     * @return array{int, int}
     */
    private static function decodeSignedValue(string $encoded, int $index): array
    {
        $length = strlen($encoded);
        $result = 0;
        $shift = 0;
        do {
            if ($index >= $length) {
                throw new ConnectorError(
                    statusCode: null,
                    providerCode: ProviderCode::Unknown,
                    providerMessage: 'Malformed polyline',
                );
            }
            $b = ord($encoded[$index]) - 63;
            if ($b < 0) {
                throw new ConnectorError(
                    statusCode: null,
                    providerCode: ProviderCode::Unknown,
                    providerMessage: 'Malformed polyline',
                );
            }
            $index++;
            $result |= ($b & 0x1F) << $shift;
            $shift += 5;
        } while ($b >= 0x20);

        return [($result & 1) !== 0 ? ~($result >> 1) : ($result >> 1), $index];
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
     * Invert the vendor `waypoints[].waypoint_index` (visit-position per input
     * waypoint, in input order) into the canonical full visiting sequence of
     * INPUT indices. Returns null when any index is missing or out of range.
     *
     * @param list<mixed> $waypoints
     * @return list<int>|null
     */
    private static function invertWaypointIndices(array $waypoints): ?array
    {
        $count = count($waypoints);
        /** @var array<int,int> $order */
        $order = [];
        foreach ($waypoints as $inputIdx => $wp) {
            if (!is_array($wp)) {
                return null;
            }
            $pos = $wp['waypoint_index'] ?? null;
            if (is_int($pos)) {
                $posInt = $pos;
            } elseif (is_numeric($pos)) {
                $posInt = (int) $pos;
            } else {
                return null;
            }
            if ($posInt < 0 || $posInt >= $count) {
                return null;
            }
            $order[$posInt] = $inputIdx;
        }

        ksort($order);
        if (count($order) !== $count) {
            // Duplicate / collided positions — not a valid permutation.
            return null;
        }

        return array_values($order);
    }
}
