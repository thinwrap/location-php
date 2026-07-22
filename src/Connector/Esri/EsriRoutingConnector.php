<?php

declare(strict_types=1);

namespace Thinwrap\Location\Connector\Esri;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Location\Base\BaseConnector;
use Thinwrap\Location\Config\EsriConfig;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\Contract\RoutingConnectorInterface;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Routing\RoutingLeg;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\DTO\Routing\RoutingResult;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Util\Passthrough;
use Thinwrap\Location\Util\Polyline;

/**
 * Esri (ArcGIS) Route NAServer connector — PHP mirror of the TS connector
 *
 * Posts to
 * https://route-api.arcgis.com/arcgis/rest/services/World/Route/NAServer/Route_World/solve
 * with an ESRI FeatureSet `stops` payload (`{ features: [{ geometry: { x, y,
 * spatialReference: { wkid: 4326 } } }, ...] }`). Dual-auth (`apiKey` xor
 * `arcgisToken`) is resolved via {@see EsriConfig::bearerToken()}.
 *
 * Esri's signature quirk: the service returns HTTP 200 OK with an
 * `error: { code, message }` body for application-layer failures (invalid
 * token, malformed query, no route found). This connector inspects the body
 * even on success status codes and throws a {@see ConnectorError} for either
 * path. Result polyline is rebuilt inline from `routes.features[0].geometry.
 * paths` (ESRI `[lng, lat]` pairs, NOT polyline-encoded on the wire) →
 * {@see Polyline::encodePolyline()} (Google precision-5).
 */
final class EsriRoutingConnector extends BaseConnector implements RoutingConnectorInterface
{
    private const ROUTE_URL = 'https://route-api.arcgis.com/arcgis/rest/services/World/Route/NAServer/Route_World/solve';
    private const MINUTES_TO_SECONDS = 60;

    public function __construct(
        private readonly EsriConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        parent::__construct($httpClient, $requestFactory, $streamFactory);
    }

    public function getProviderId(): string
    {
        return LocationProviderId::Esri->value;
    }

    public function route(RoutingOptions $options): RoutingResult
    {
        $waypoints = $options->waypoints;
        if (count($waypoints) < 2) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Esri Routing requires at least two waypoints',
            );
        }

        // reject non-finite waypoint coordinates before they reach the inline
        // json_encode() below — that encode runs OUTSIDE sendPostForm's error
        // handling and, with JSON_THROW_ON_ERROR, a NaN/INF coordinate would
        // raise a raw \JsonException instead of the unified ConnectorError.
        // Mirrors the isochrone connectors' center-finiteness pre-flight.
        foreach ($waypoints as $wp) {
            $wp->assertFinite('Esri route');
        }

        $stops = $this->buildStopsFeatureSet($waypoints);

        $form = [
            'f' => 'json',
            'token' => $this->config->bearerToken(),
            'stops' => json_encode($stops, JSON_THROW_ON_ERROR),
            'returnRoutes' => 'true',
            'returnDirections' => 'true',
            'directionsLengthUnits' => 'esriNAUMeters',
            'directionsOutputType' => 'esriDOTComplete',
            'outputLines' => 'esriNAOutputLineTrueShapeWithMeasure',
            'outSR' => '4326',
        ];

        // ESRI findBestSequence optimizes an OPEN route (optionally preserving the
        // first/last stop); it has no closed round-trip mode.
        if ($options->isRoundTrip) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::UnsupportedOption,
                providerMessage: 'ESRI findBestSequence optimizes an open route and cannot return a closed round trip; remove isRoundTrip or use a provider that supports it (e.g. Mapbox/OSRM).',
            );
        }

        if ($options->optimize) {
            $form['findBestSequence'] = 'true';
            // Needed to recover the optimized visiting order: the `stops`
            // FeatureSet carries each stop's 1-based `Sequence` (there is no
            // `Stops` route attribute).
            $form['returnStops'] = 'true';
            if ($options->optimizeFixedOrigin) {
                $form['preserveFirstStop'] = 'true';
            }
            if ($options->optimizeFixedDestination) {
                $form['preserveLastStop'] = 'true';
            }
        }

        $travelMode = EsriTravelModes::map($options->travelMode, 'Routing');
        if ($travelMode !== null) {
            $form['travelMode'] = $travelMode;
        }

        $restrictions = $this->buildRestrictions($options);
        if ($restrictions !== '') {
            $form['restrictionAttributeNames'] = $restrictions;
        }

        if ($options->departureTime !== null) {
            // ESRI accepts epoch milliseconds for `startTime`.
            $form['startTime'] = (string) ($options->departureTime->getTimestamp() * 1000);
        }

        // Passthrough `body` overlays form fields; headers/query overlay as
        // usual via Passthrough::merge.
        $merged = Passthrough::merge(
            $form,
            [],
            [],
            $this->buildPassthroughBuckets($options),
        );

        /** @var array<string, string> $formData */
        $formData = [];
        foreach ($merged['body'] as $key => $value) {
            $formData[$key] = $this->stringifyFormValue($value);
        }

        $response = $this->sendPostForm(self::ROUTE_URL, $formData, $merged['headers'], $merged['query']);

        $data = $this->decodeJson($response);

        // body inspection regardless of HTTP status.
        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response, $data);
        }

        if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
            $this->raiseBodyError($response->getStatusCode(), $data);
        }

        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'Esri Routing returned non-JSON body',
                cause: $data,
            );
        }

        return $this->normalizeSuccess($data, $waypoints, $response->getStatusCode());
    }

    /**
     * Build an ESRI FeatureSet for the `stops` form parameter.
     *
     * @param list<LatLng> $waypoints
     * @return array{features: list<array{geometry: array{x: float, y: float, spatialReference: array{wkid: int}}}>}
     */
    private function buildStopsFeatureSet(array $waypoints): array
    {
        $features = [];
        foreach ($waypoints as $wp) {
            $features[] = [
                'geometry' => [
                    'x' => $wp->lng,
                    'y' => $wp->lat,
                    'spatialReference' => ['wkid' => 4326],
                ],
            ];
        }

        return ['features' => $features];
    }

    private function buildRestrictions(RoutingOptions $options): string
    {
        $restrictions = [];
        if ($options->avoidTolls) {
            $restrictions[] = 'Avoid Toll Roads';
        }
        if ($options->avoidFerries) {
            $restrictions[] = 'Avoid Ferries';
        }
        if ($options->avoidHighways) {
            // Verified ESRI World Route restriction-attribute name; pinned to
            // match the TS sibling (esri.routing.connector.ts) for cross-language
            // parity. `'Avoid Limited Access Freeways'` is not the catalog name
            // and would be silently ignored by the service.
            $restrictions[] = 'Avoid Limited Access Roads';
        }

        return implode(',', $restrictions);
    }

    /**
     * Normalize a 2xx ESRI response into a {@see RoutingResult}.
     *
     * @param array<string, mixed> $data
     * @param list<LatLng> $waypoints
     */
    private function normalizeSuccess(array $data, array $waypoints, int $status): RoutingResult
    {
        $routes = (isset($data['routes']) && is_array($data['routes'])) ? $data['routes'] : [];
        $features = (isset($routes['features']) && is_array($routes['features'])) ? $routes['features'] : [];
        $feature = $features[0] ?? null;
        if (!is_array($feature)) {
            throw new ConnectorError(
                statusCode: $status,
                providerCode: ProviderCode::Unknown,
                providerMessage: 'Esri Routing returned no routes',
                cause: $data,
            );
        }

        $attrs = (isset($feature['attributes']) && is_array($feature['attributes'])) ? $feature['attributes'] : [];

        // Totals come from the directions summary, which is travel-mode-independent
        // (`totalLength` in meters via directionsLengthUnits=esriNAUMeters,
        // `totalTime` in minutes). The route feature's `Total_*` attributes are
        // named after the active impedance — driving reports `Total_TravelTime`,
        // walking reports `Total_WalkTime`, and neither `Total_Length` nor
        // `Total_Time` is emitted at all — so reading them directly silently
        // yields 0 for any non-driving mode. Fall back to the attributes only when
        // the summary is absent (verified live against route-api.arcgis.com,
        // 2026-07-21; kept identical to the TS sibling).
        $summary = self::directionsSummary($data);
        $summaryLen = (is_array($summary) && isset($summary['totalLength'])) ? $summary['totalLength'] : null;
        $summaryTime = (is_array($summary) && isset($summary['totalTime'])) ? $summary['totalTime'] : null;

        if ($summaryLen !== null) {
            $totalDistanceMeters = self::toFloat($summaryLen);
        } elseif (($attrs['Total_Length'] ?? null) !== null) {
            $totalDistanceMeters = self::toFloat($attrs['Total_Length']);
        } elseif (($attrs['Total_Kilometers'] ?? null) !== null) {
            $totalDistanceMeters = self::toFloat($attrs['Total_Kilometers']) * 1000.0;
        } else {
            $totalDistanceMeters = 0.0;
        }

        if ($summaryTime !== null) {
            $totalTimeMin = self::toFloat($summaryTime);
        } else {
            $totalTimeMin = self::toFloat($attrs['Total_Time'] ?? $attrs['Total_TravelTime'] ?? $attrs['Total_WalkTime'] ?? 0);
        }
        $totalDurationSeconds = $totalTimeMin * self::MINUTES_TO_SECONDS;

        $legs = $this->reconstructLegs($data, $waypoints, $totalDistanceMeters, $totalDurationSeconds);

        // Inline ESRI-paths → LatLng[] → precision-5 polyline (parity with TS
        // /). ESRI `paths` is list<list<[lng,lat]>>.
        $allPoints = [];
        $geometry = (isset($feature['geometry']) && is_array($feature['geometry'])) ? $feature['geometry'] : [];
        $paths = (isset($geometry['paths']) && is_array($geometry['paths'])) ? $geometry['paths'] : [];
        foreach ($paths as $path) {
            if (!is_array($path)) {
                continue;
            }
            foreach ($path as $point) {
                if (!is_array($point) || !isset($point[0], $point[1])) {
                    continue;
                }
                $allPoints[] = new LatLng(self::toFloat($point[1]), self::toFloat($point[0]));
            }
        }
        $polyline = Polyline::encodePolyline($allPoints);

        $waypointOrder = $this->extractWaypointOrder($data, count($waypoints));

        return new RoutingResult(
            legs: $legs,
            totalDistanceMeters: $totalDistanceMeters,
            totalDurationSeconds: $totalDurationSeconds,
            polyline: $polyline,
            waypointOrder: $waypointOrder,
            raw: $data,
        );
    }

    /**
     * Extract the route-level directions summary (travel-mode-independent
     * totals). ESRI returns `directions` either as a single object with a
     * `summary` key or as a list whose first entry carries it.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function directionsSummary(array $data): ?array
    {
        $container = $data['directions'] ?? null;
        if (!is_array($container)) {
            return null;
        }
        if (isset($container['summary']) && is_array($container['summary'])) {
            return $container['summary'];
        }
        if (isset($container[0]) && is_array($container[0]) && isset($container[0]['summary']) && is_array($container[0]['summary'])) {
            return $container[0]['summary'];
        }

        return null;
    }

    /**
     * Reconstruct per-leg distances/durations from the ESRI directions
     * FeatureSet. Each direction step carries `length` (meters when
     * `directionsLengthUnits=esriNAUMeters`) and `time` (minutes). Legs are
     * delimited by `maneuverType=esriDMTStop` steps.
     *
     * Falls back to even-split of totals when directions are absent.
     *
     * @param array<string, mixed> $data
     * @param list<LatLng> $waypoints
     * @return list<RoutingLeg>
     */
    private function reconstructLegs(array $data, array $waypoints, float $totalDistance, float $totalDuration): array
    {
        $numLegs = max(1, count($waypoints) - 1);

        $directionsContainer = $data['directions'] ?? null;
        $directionSet = null;
        if (is_array($directionsContainer)) {
            if (isset($directionsContainer['features']) && is_array($directionsContainer['features'])) {
                $directionSet = $directionsContainer['features'];
            } elseif (isset($directionsContainer[0]) && is_array($directionsContainer[0])) {
                $first = $directionsContainer[0];
                if (isset($first['features']) && is_array($first['features'])) {
                    $directionSet = $first['features'];
                }
            }
        }

        if (!is_array($directionSet) || $directionSet === []) {
            // Even-split fallback for older responses or directions=off.
            return array_fill(0, $numLegs, new RoutingLeg(
                distanceMeters: $totalDistance / $numLegs,
                durationSeconds: $totalDuration / $numLegs,
            ));
        }

        /** @var list<RoutingLeg> $legs */
        $legs = [];
        $accDist = 0.0;
        $accTime = 0.0;
        $passedFirstStop = false;

        foreach ($directionSet as $step) {
            if (!is_array($step)) {
                continue;
            }
            $stepAttrs = (isset($step['attributes']) && is_array($step['attributes'])) ? $step['attributes'] : [];
            $maneuver = isset($stepAttrs['maneuverType']) && is_string($stepAttrs['maneuverType'])
                ? $stepAttrs['maneuverType']
                : null;

            $isStop = $maneuver === 'esriDMTStop';

            if ($isStop) {
                if (!$passedFirstStop) {
                    // First `esriDMTStop` is the route origin — start
                    // accumulating after it.
                    $passedFirstStop = true;
                    $accDist = 0.0;
                    $accTime = 0.0;
                    continue;
                }
                $legs[] = new RoutingLeg(
                    distanceMeters: $accDist,
                    durationSeconds: $accTime * self::MINUTES_TO_SECONDS,
                );
                $accDist = 0.0;
                $accTime = 0.0;
                continue;
            }

            if (!$passedFirstStop) {
                continue;
            }

            $accDist += self::toFloat($stepAttrs['length'] ?? 0);
            $accTime += self::toFloat($stepAttrs['time'] ?? 0);
        }

        // If the directions stream did not end on a stop step, flush remainder.
        if ($accDist > 0.0 || $accTime > 0.0) {
            $legs[] = new RoutingLeg(
                distanceMeters: $accDist,
                durationSeconds: $accTime * self::MINUTES_TO_SECONDS,
            );
        }

        if ($legs === []) {
            return array_fill(0, $numLegs, new RoutingLeg(
                distanceMeters: $totalDistance / $numLegs,
                durationSeconds: $totalDuration / $numLegs,
            ));
        }

        return $legs;
    }

    /**
     * Derive the optimized visiting sequence when `findBestSequence=true` was
     * requested. ESRI returns the `stops` FeatureSet (`returnStops=true`) in
     * INPUT order, each stop carrying a 1-based `Sequence` = its position in the
     * optimized route. Invert to the canonical `waypointOrder` = full visiting
     * sequence of INPUT indices (`order[Sequence - 1] = inputIndex`). Return
     * null when the sequence data is absent, incomplete, or malformed.
     *
     * @param array<string, mixed> $data
     * @return list<int>|null
     */
    private function extractWaypointOrder(array $data, int $totalStops): ?array
    {
        $stops = (isset($data['stops']) && is_array($data['stops'])) ? $data['stops'] : [];
        $features = (isset($stops['features']) && is_array($stops['features'])) ? $stops['features'] : [];
        if (count($features) !== $totalStops) {
            return null;
        }

        $order = array_fill(0, $totalStops, -1);
        $inputIdx = 0;
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                return null;
            }
            $attrs = (isset($feature['attributes']) && is_array($feature['attributes'])) ? $feature['attributes'] : [];
            $seq = $attrs['Sequence'] ?? null;
            if (!is_int($seq) || $seq < 1 || $seq > $totalStops || $order[$seq - 1] !== -1) {
                return null;
            }
            $order[$seq - 1] = $inputIdx;
            $inputIdx++;
        }

        return $order;
    }

    /**
     * Map vendor HTTP status + decoded body shape to {@see ProviderCode}.
     * Handles both HTTP-level codes and Esri's
     * 200-with-error-body case via `body.error.code`.
     *
     * @param mixed $body Decoded vendor body (may be null/scalar/array).
     */
    private function mapVendorError(int $httpStatus, mixed $body): ProviderCode
    {
        $bodyErrorCode = null;
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $code = $body['error']['code'] ?? null;
            if (is_int($code)) {
                $bodyErrorCode = $code;
            } elseif (is_string($code) && is_numeric($code)) {
                $bodyErrorCode = (int) $code;
            }
        }

        // Precedence fix (CR pre-existing): a rate-limited response (HTTP 429)
        // that ALSO carries an in-body error code must still classify as
        // RateLimited — the `429 → RateLimited` status check takes precedence
        // over the body-code → Unknown fallthrough. (ESRI also surfaces `429`
        // as a body code.)
        if ($httpStatus === 429 || $bodyErrorCode === 429) {
            return ProviderCode::RateLimited;
        }

        if ($bodyErrorCode !== null) {
            if ($bodyErrorCode === 498 || $bodyErrorCode === 499 || $bodyErrorCode === 403) {
                return ProviderCode::AuthFailed;
            }
            if ($bodyErrorCode === 400 || $bodyErrorCode === 404) {
                return ProviderCode::InvalidRequest;
            }
            if ($bodyErrorCode === 500) {
                return ProviderCode::ProviderUnavailable;
            }

            return ProviderCode::Unknown;
        }

        if ($httpStatus === 401 || $httpStatus === 403) {
            return ProviderCode::AuthFailed;
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
     * Build the human-readable `providerMessage`, weaving in parsed
     * Retry-After seconds when present by design.
     *
     * @param mixed $body Decoded vendor body.
     */
    private function formatProviderMessage(mixed $body, ?string $retryAfter): ?string
    {
        $base = null;
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $msg = $body['error']['message'] ?? null;
            if (is_string($msg) && $msg !== '') {
                $base = $msg;
            } else {
                $code = $body['error']['code'] ?? null;
                if (is_int($code) || (is_string($code) && $code !== '')) {
                    $base = (string) $code;
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
     * Throw a {@see ConnectorError} for a non-2xx HTTP response.
     */
    private function raiseHttpError(ResponseInterface $response, mixed $body): never
    {
        $status = $response->getStatusCode();
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
            message: "Esri Routing failed: {$status}",
            cause: $cause,
        );
    }

    /**
     * Throw a {@see ConnectorError} for a 200-OK ESRI response carrying an
     * `error` body.
     *
     * @param array<string, mixed> $body
     */
    private function raiseBodyError(int $status, array $body): never
    {
        $errorPayload = is_array($body['error'] ?? null) ? $body['error'] : null;

        throw new ConnectorError(
            statusCode: $status,
            providerCode: $this->mapVendorError($status, $body),
            providerMessage: $this->formatProviderMessage($body, null),
            message: "Esri Routing returned error body: {$status}",
            cause: $errorPayload ?? $body,
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

    private function stringifyFormValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return '';
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
