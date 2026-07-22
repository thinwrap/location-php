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
use Thinwrap\Location\Contract\RoutingConnectorInterface;
use Thinwrap\Location\DTO\Routing\RoutingLeg;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\DTO\Routing\RoutingResult;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Util\Passthrough;

/**
 * Google Routes v2 connector — PHP per-connector template.
 *
 * Posts to
 * https://routes.googleapis.com/directions/v2:computeRoutes with the
 * X-Goog-Api-Key / X-Goog-FieldMask headers and the Routes v2 request body.
 * Normalizes the response to {@see RoutingResult} (meters, seconds, Google
 * precision-5 polyline). No retry, no caching, no stateful behaviour.
 *
 * Subsequent per-connector stories (3.7–3.29) follow this story's body shape:
 * 1. Build vendor body.
 * 2. {@see Passthrough::merge()} body / headers / query.
 * 3. Call {@see BaseConnector::sendPostJson()} (or sendGet/sendPostForm).
 * 4. On non-2xx, parse body + raise {@see ConnectorError} with
 * {@see mapVendorError()} ProviderCode + Retry-After surfacing by design.
 * 5. Normalize 2xx body into the operation DTO.
 */
final class GoogleRoutingConnector extends BaseConnector implements RoutingConnectorInterface
{
    private const ROUTES_URL = 'https://routes.googleapis.com/directions/v2:computeRoutes';

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

    public function route(RoutingOptions $options): RoutingResult
    {
        $waypoints = $options->waypoints;
        if (count($waypoints) < 2) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Google Routing requires at least two waypoints',
            );
        }
        $first = $waypoints[0];
        $last = $waypoints[count($waypoints) - 1];

        $origin = ['location' => ['latLng' => ['latitude' => $first->lat, 'longitude' => $first->lng]]];
        $destination = ['location' => ['latLng' => ['latitude' => $last->lat, 'longitude' => $last->lng]]];

        $intermediates = [];
        for ($i = 1; $i < count($waypoints) - 1; $i++) {
            $wp = $waypoints[$i];
            $intermediates[] = ['location' => ['latLng' => ['latitude' => $wp->lat, 'longitude' => $wp->lng]]];
        }

        $travelMode = $this->mapTravelMode($options->travelMode);

        /** @var array<string, mixed> $body */
        $body = [
            'origin' => $origin,
            'destination' => $destination,
            'travelMode' => $travelMode,
            'polylineEncoding' => 'ENCODED_POLYLINE',
        ];

        // Google rejects `routingPreference` for WALK/BICYCLE ("Routing
        // preference cannot be set for WALK or BICYCLE routing mode.") — only
        // DRIVE and TWO_WHEELER accept it. Overridable via `_passthrough`.
        if ($travelMode === 'DRIVE' || $travelMode === 'TWO_WHEELER') {
            $body['routingPreference'] = $options->departureTime !== null ? 'TRAFFIC_AWARE' : 'TRAFFIC_UNAWARE';
        }

        if ($intermediates !== []) {
            $body['intermediates'] = $intermediates;
        }

        if ($options->optimize && $intermediates !== []) {
            $body['optimizeWaypointOrder'] = true;
        }

        if ($options->departureTime !== null) {
            $body['departureTime'] = $options->departureTime->format('c');
        }

        if ($options->avoidTolls || $options->avoidFerries || $options->avoidHighways) {
            $body['routeModifiers'] = [
                'avoidTolls' => $options->avoidTolls,
                'avoidFerries' => $options->avoidFerries,
                'avoidHighways' => $options->avoidHighways,
            ];
        }

        $fieldMask = [
            'routes.legs.distanceMeters',
            'routes.legs.duration',
            'routes.legs.staticDuration',
            'routes.distanceMeters',
            'routes.duration',
            'routes.staticDuration',
            'routes.polyline.encodedPolyline',
        ];

        if ($options->optimize) {
            $fieldMask[] = 'routes.optimizedIntermediateWaypointIndex';
        }

        /** @var array<string, string> $headers */
        $headers = [
            'X-Goog-Api-Key' => $this->config->apiKey,
            'X-Goog-FieldMask' => implode(',', $fieldMask),
        ];

        $merged = Passthrough::merge($body, $headers, [], $this->buildPassthroughBuckets($options));

        $response = $this->sendPostJson(self::ROUTES_URL, $merged['body'], $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response);
        }

        $data = $this->decodeJson($response);

        $routes = (is_array($data) && isset($data['routes']) && is_array($data['routes'])) ? $data['routes'] : [];
        $route = $routes[0] ?? null;
        if (!is_array($route)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'Google Routing returned no routes',
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
                distanceMeters: self::toFloat($leg['distanceMeters'] ?? 0),
                durationSeconds: (float) self::parseDuration($leg['duration'] ?? '0s'),
            );
        }

        $polylineNode = (isset($route['polyline']) && is_array($route['polyline'])) ? $route['polyline'] : [];
        $polyline = isset($polylineNode['encodedPolyline']) && is_string($polylineNode['encodedPolyline'])
            ? $polylineNode['encodedPolyline']
            : '';

        // Canonical `waypointOrder` = full visiting sequence of INPUT indices,
        // 0-based, origin/destination inclusive. Google reports
        // `optimizedIntermediateWaypointIndex` — the optimized order of the
        // INTERMEDIATE waypoints only, as 0-based intermediate indices. Project
        // to absolute input indices (`i + 1`, origin is 0), prepend the origin
        // (0) and append the destination (N - 1). For a round trip Google treats
        // every non-origin waypoint as an intermediate, so origin + projected
        // intermediates already cover all input indices (no separate append).
        $waypointOrder = null;
        if (isset($route['optimizedIntermediateWaypointIndex']) && is_array($route['optimizedIntermediateWaypointIndex'])) {
            /** @var list<int> $projected */
            $projected = [];
            foreach ($route['optimizedIntermediateWaypointIndex'] as $idx) {
                if (is_int($idx)) {
                    $projected[] = $idx + 1;
                } elseif (is_numeric($idx)) {
                    $projected[] = (int) $idx + 1;
                }
            }
            $waypointOrder = $options->isRoundTrip
                ? [0, ...$projected]
                : [0, ...$projected, count($waypoints) - 1];
        }

        return new RoutingResult(
            legs: $legs,
            totalDistanceMeters: self::toFloat($route['distanceMeters'] ?? 0),
            totalDurationSeconds: (float) self::parseDuration($route['duration'] ?? '0s'),
            polyline: $polyline,
            waypointOrder: $waypointOrder,
            raw: $data,
        );
    }

    /**
     * Map vendor HTTP status + body shape to {@see ProviderCode}. Mirrors TS
     * Locality: per-connector
     *
     * @param mixed $body Decoded vendor error body (may be null/scalar/array).
     */
    private function mapVendorError(int $httpStatus, mixed $body): ProviderCode
    {
        // Prefer the structured google.rpc.ErrorInfo reason over the HTTP status:
        // Google returns 400 INVALID_ARGUMENT for an invalid key.
        $reasonCode = $this->reasonProviderCode($body);
        if ($reasonCode !== null) {
            return $reasonCode;
        }

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
     * Map a google.rpc.ErrorInfo `reason` (from `error.details[]`) to a
     * ProviderCode, or null to fall back to the HTTP-status mapping. This lets a
     * bad/blocked key surface as auth_failed even though Google reports it as
     * HTTP 400 INVALID_ARGUMENT.
     *
     * @param mixed $body Decoded vendor error body.
     */
    private function reasonProviderCode(mixed $body): ?ProviderCode
    {
        if (!is_array($body) || !isset($body['error']) || !is_array($body['error'])) {
            return null;
        }
        $details = $body['error']['details'] ?? null;
        if (!is_array($details)) {
            return null;
        }
        $reason = null;
        foreach ($details as $d) {
            if (!is_array($d)) {
                continue;
            }
            $type = $d['@type'] ?? null;
            $isErrorInfo = (($d['domain'] ?? null) === 'googleapis.com')
                || (is_string($type) && str_ends_with($type, 'google.rpc.ErrorInfo'));
            if ($isErrorInfo && isset($d['reason']) && is_string($d['reason']) && $d['reason'] !== '') {
                $reason = $d['reason'];
                break;
            }
        }
        if ($reason === null) {
            return null;
        }

        $authReasons = [
            'API_KEY_INVALID', 'API_KEY_SERVICE_BLOCKED', 'API_KEY_HTTP_REFERRER_BLOCKED',
            'API_KEY_IP_ADDRESS_BLOCKED', 'API_KEY_ANDROID_APP_BLOCKED', 'API_KEY_IOS_APP_BLOCKED',
            'CREDENTIALS_MISSING', 'ACCESS_TOKEN_EXPIRED', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT',
            'ACCESS_TOKEN_TYPE_UNSUPPORTED', 'ACCOUNT_STATE_INVALID', 'CONSUMER_INVALID',
            'CONSUMER_SUSPENDED', 'USER_PROJECT_DENIED', 'SERVICE_DISABLED', 'BILLING_DISABLED',
        ];
        if (in_array($reason, $authReasons, true)) {
            return ProviderCode::AuthFailed;
        }
        if ($reason === 'RATE_LIMIT_EXCEEDED' || $reason === 'RESOURCE_QUOTA_EXCEEDED') {
            return ProviderCode::RateLimited;
        }

        return null;
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
            message: "Google Routing failed: {$status}",
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
}
