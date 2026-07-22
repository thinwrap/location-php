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
use Thinwrap\Location\Contract\RoutingConnectorInterface;
use Thinwrap\Location\DTO\Routing\RoutingLeg;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\DTO\Routing\RoutingResult;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Util\Coordinate;
use Thinwrap\Location\Util\Passthrough;

/**
 * OSRM (Open Source Routing Machine) routing connector — PHP mirror of TS
 * **Two architectural outliers** captured here:
 *
 * 1. `/route/v1` vs `/trip/v1` endpoint dispatch — `/trip` is used
 *      when any optimization flag is set.
 * 2. Pre-flight validation — raises typed {@see ConnectorError}
 *      with the location-extended {@see ProviderCode} values for fields
 *      and options OSRM cannot support, BEFORE any HTTP call. Pre-response
 * errors carry `statusCode: null`.
 *
 * No auth: consumers self-host OSRM. Any auth in front of the
 * instance is the consumer's responsibility (reverse proxy). `baseUrl` is
 * required and validated synchronously in the constructor — there is no
 * public-demo default.
 *
 * OSRM emits precision-5 polyline geometry directly when `geometries=polyline`
 * is set, so the connector forwards `routes[0].geometry` to
 * {@see RoutingResult::$polyline} without re-encoding (parity).
 *
 * dispatch + error classification live entirely inside this
 * connector (no shared translator middleware).
 */
final class OsrmRoutingConnector extends BaseConnector implements RoutingConnectorInterface
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

    public function route(RoutingOptions $options): RoutingResult
    {
        // Pre-flight validation — raised before any HTTP work.
        $this->validateOsrmCompat($options);

        $waypoints = $options->waypoints;
        if (count($waypoints) < 2) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'OSRM Routing requires at least two waypoints',
            );
        }

        $profile = $this->mapProfile($options->travelMode);
        $coords = Coordinate::joinLngLat($waypoints, ';');

        // TS-identical `/trip` trigger restored. Any optimization flag
        // (master switch OR either fixed flag OR roundtrip) dispatches to
        // `/trip`. This is safe now that `optimizeFixedOrigin` /
        // `optimizeFixedDestination` default to `false` (DTO default change is
        // the single root fix; the trigger itself is TS-identical).
        $useTrip = $options->optimize
            || $options->optimizeFixedOrigin
            || $options->optimizeFixedDestination
            || $options->isRoundTrip;

        $endpoint = $useTrip ? 'trip' : 'route';
        $url = rtrim($this->config->baseUrl, '/') . "/{$endpoint}/v1/{$profile}/{$coords}";

        /** @var array<string, string|int|float|bool> $query */
        $query = [
            'overview'    => 'full',
            'geometries'  => 'polyline',
            'steps'       => 'true',
            'annotations' => 'duration,distance',
        ];

        if (!$useTrip) {
            $query['alternatives'] = 'false';
        } else {
            $source      = $options->optimizeFixedOrigin ? 'first' : 'any';
            $destination = $options->optimizeFixedDestination ? 'last' : 'any';
            // OSRM rejects source=any + destination=any with roundtrip=false
            // (HTTP 400 NotImplemented). A plain `optimize` (neither endpoint
            // fixed, open route) therefore keeps the input's first & last fixed
            // and reorders the middle — matching the Mapbox Optimization v1 sibling.
            if (!$options->isRoundTrip && $source === 'any' && $destination === 'any') {
                $source      = 'first';
                $destination = 'last';
            }
            $query['source']      = $source;
            $query['destination'] = $destination;
            $query['roundtrip']   = $options->isRoundTrip ? 'true' : 'false';
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options));

        $response = $this->sendGet($url, $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response);
        }

        $data = $this->decodeJson($response);

        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'OSRM Routing returned non-JSON body',
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

        $routes = (isset($data['routes']) && is_array($data['routes'])) ? $data['routes'] : [];
        // `/trip/v1` returns the trips under `trips` instead of `routes`.
        if ($routes === [] && isset($data['trips']) && is_array($data['trips'])) {
            $routes = $data['trips'];
        }
        $route = $routes[0] ?? null;
        if (!is_array($route)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'OSRM Routing returned no routes',
                cause: $data,
            );
        }

        $legsRaw = (isset($route['legs']) && is_array($route['legs'])) ? $route['legs'] : [];
        /** @var list<RoutingLeg> $legs */
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
        if (isset($route['geometry']) && is_string($route['geometry'])) {
            // OSRM emits precision-5 polyline when `geometries=polyline` is set,
            // which matches Thinwrap's normalized polyline shape — no
            // re-encoding required.
            $polyline = $route['geometry'];
        }

        $waypointOrder = null;
        if ($useTrip && isset($data['waypoints']) && is_array($data['waypoints'])) {
            $waypointOrder = $this->extractWaypointOrder($data['waypoints']);
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
     * Pre-flight validation. Raises typed
     * {@see ConnectorError} with the location-extended {@see ProviderCode}
     * values for fields and options OSRM cannot support.
     */
    private function validateOsrmCompat(RoutingOptions $options): void
    {
        if ($options->departureTime !== null) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::UnsupportedField,
                providerMessage: 'OSRM does not support departureTime',
            );
        }

        /** @var list<array{0: string, 1: bool}> $avoidFlags */
        $avoidFlags = [
            ['avoidTolls', $options->avoidTolls],
            ['avoidFerries', $options->avoidFerries],
            ['avoidHighways', $options->avoidHighways],
        ];
        foreach ($avoidFlags as [$flag, $value]) {
            if ($value === true) {
                throw new ConnectorError(
                    statusCode: null,
                    providerCode: ProviderCode::UnsupportedOption,
                    providerMessage: "{$flag} is not supported by OSRM",
                );
            }
        }

        // NOTE: the previous "invalid /trip combo" preflight is gone. It rejected
        // source=any/destination=any/roundtrip=false, but the query builder no
        // longer emits that combo — a plain `optimize` maps to
        // source=first/destination=last (open route, endpoints kept, middle
        // reordered), which OSRM accepts (see the $useTrip query block above).
    }

    /**
     * Map OSRM in-body `code` values (returned even on HTTP 200) to
     * {@see ProviderCode}. `NoRoute` defaults
     * to `invalid_request` — `profile_not_configured` is raised ONLY when
     * the response carries an explicit profile signal in
     * {@see formatBodyMessage()} pattern matching.
     */
    private function mapBodyCode(string $code): ProviderCode
    {
        return match ($code) {
            'NoRoute', 'NoSegment', 'NoTrips', 'InvalidQuery', 'InvalidOptions', 'InvalidValue' => ProviderCode::InvalidRequest,
            'TooBig' => ProviderCode::InvalidRequest,
            default  => ProviderCode::Unknown,
        };
    }

    /**
     * Map vendor HTTP-level status codes to {@see ProviderCode}. Vanilla OSRM
     * has no auth and no rate limits — consumers' reverse proxies may add
     * them (401/403/429), so surface those generically. Body-level errors
     * (where OSRM responds 200 with `code != 'Ok'`) are handled separately
     * via {@see mapBodyCode()}.
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
            message: "OSRM Routing failed: {$status}",
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
     * Extract the canonical waypoint order from an OSRM `/trip` response.
     *
     * Canonical `waypointOrder` = full visiting sequence of INPUT indices,
     * 0-based, origin/destination inclusive (PINNED cross-language contract).
     * OSRM returns `waypoints[]` in INPUT order, where each `waypoint_index` is
     * the position that input waypoint occupies in the optimized trip — i.e.
     * the INVERSE of the canonical. Invert it: place each input index at its
     * visit position. Returns null when any index is missing or out of range.
     *
     * @param list<mixed>|array<int|string, mixed> $waypoints
     * @return list<int>|null
     */
    private function extractWaypointOrder(array $waypoints): ?array
    {
        $wps = array_values($waypoints);
        $count = count($wps);

        /** @var array<int,int> $order */
        $order = [];
        foreach ($wps as $inputIdx => $wp) {
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
            return null;
        }

        return array_values($order);
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

    /**
     * Map {@see TravelMode} to OSRM profile name.
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
