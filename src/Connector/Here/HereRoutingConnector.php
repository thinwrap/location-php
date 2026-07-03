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
use Thinwrap\Location\Contract\RoutingConnectorInterface;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Routing\RoutingLeg;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\DTO\Routing\RoutingResult;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Providers\Here\DTO\HereRoutingOptions;
use Thinwrap\Location\Providers\Here\Enum\HereTransportMode;
use Thinwrap\Location\Util\Passthrough;
use Thinwrap\Location\Util\Polyline;

/**
 * HERE Routing v8 connector — PHP mirror of the TS connector (architectural outlier).
 *
 * Two endpoints, two dispatch shapes:
 *
 *   - `GET https://router.hereapi.com/v8/routes` for plain routing.
 *   - **Two-call** workflow when any optimization flag is set:
 *     1. `GET https://wps.hereapi.com/v8/findsequence2` to compute the order.
 *     2. `GET https://router.hereapi.com/v8/routes` with the reordered `via`.
 *
 * The two-call mechanic is sequential synchronous PSR-18 (PHP has no native
 * async). Polyline is HERE flex-polyline → Google precision-5 via
 * {@see Polyline::decodeFlexPolyline()} + {@see Polyline::encodePolyline()}.
 *
 * Provider-narrowed input: {@see HereRoutingOptions} extends
 * {@see RoutingOptions} adding a {@see HereTransportMode} field. When set,
 * the narrowed `transportMode` overrides the base {@see TravelMode} mapping.
 * dispatch + narrowing live entirely inside this connector.
 */
final class HereRoutingConnector extends BaseConnector implements RoutingConnectorInterface
{
    private const ROUTER_URL = 'https://router.hereapi.com/v8/routes';
    private const SEQUENCE_URL = 'https://wps.hereapi.com/v8/findsequence2';

    public function __construct(
        private readonly HereConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        parent::__construct($httpClient, $requestFactory, $streamFactory);
    }

    public function getProviderId(): string
    {
        return LocationProviderId::Here->value;
    }

    public function route(RoutingOptions $options): RoutingResult
    {
        $waypoints = $options->waypoints;
        if (count($waypoints) < 2) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'HERE Routing requires at least two waypoints',
            );
        }

        // TS-identical 4-flag trigger. Any explicit optimization flag fires
        // the two-call `findsequence2` workflow. All four flags default to FALSE
        // (see RoutingOptions), so a default route does NOT fire the unrequested
        // second call (the bug fixed); but an explicitly-set fixed flag
        // (origin/destination) IS an explicit optimize request and DOES fire,
        // matching the TS connector exactly.
        $useOptimization = $options->optimize
            || $options->optimizeFixedOrigin
            || $options->optimizeFixedDestination
            || $options->isRoundTrip;

        $orderedWaypoints = $waypoints;
        $waypointOrder = null;

        if ($useOptimization && count($waypoints) > 2) {
            $sequence = $this->callFindSequence($waypoints, $options);
            $orderedWaypoints = array_map(
                static fn(int $i): LatLng => $waypoints[$i],
                $sequence,
            );
            // Canonical waypointOrder = full sequence of INPUT indices in
            // VISITING order, 0-based (PINNED cross-language contract). `$sequence`
            // is already the absolute input indices (a `list<int>`) in visiting order.
            $waypointOrder = $sequence;
        }

        $direct = $this->callRoutes($orderedWaypoints, $options);

        return new RoutingResult(
            legs: $direct->legs,
            totalDistanceMeters: $direct->totalDistanceMeters,
            totalDurationSeconds: $direct->totalDurationSeconds,
            polyline: $direct->polyline,
            waypointOrder: $waypointOrder,
            raw: $direct->raw,
        );
    }

    /**
     * Plain `/v8/routes` GET dispatch.
     *
     * @param list<LatLng> $waypoints
     */
    private function callRoutes(array $waypoints, RoutingOptions $options): RoutingResult
    {
        $first = $waypoints[0];
        $last = $waypoints[count($waypoints) - 1];
        $intermediates = array_slice($waypoints, 1, -1);

        $transportMode = $this->resolveTransportMode($options);

        $query = [
            'apiKey' => $this->config->apiKey,
            'transportMode' => $transportMode,
            'return' => 'polyline,summary',
            'routingMode' => 'fast',
            'origin' => $first->toLatLngString(),
            'destination' => $last->toLatLngString(),
        ];

        if ($options->departureTime !== null) {
            $query['departureTime'] = $options->departureTime->format('c');
        }

        $avoidFeatures = $this->buildAvoidFeatures($options);
        if ($avoidFeatures !== '') {
            $query['avoid[features]'] = $avoidFeatures;
        }

        // HERE accepts repeated `via` query parameters — `http_build_query`
        // serializes a list of values under the same key with `[]` brackets,
        // which HERE does not accept. We append intermediates by hand below.
        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options));

        $url = self::ROUTER_URL;
        if ($intermediates !== []) {
            $viaPairs = [];
            foreach ($intermediates as $wp) {
                $viaPairs[] = 'via=' . rawurlencode($wp->toLatLngString());
            }
            $url .= '?' . implode('&', $viaPairs);
        }

        $response = $this->sendGet($url, $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response, 'HERE Routing');
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
                providerMessage: 'HERE Routing returned no routes',
                cause: $data,
            );
        }

        $sections = (isset($route['sections']) && is_array($route['sections']))
            ? $route['sections']
            : [];

        /** @var list<LatLng> $allCoords */
        $allCoords = [];
        $legs = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            if (isset($section['polyline']) && is_string($section['polyline']) && $section['polyline'] !== '') {
                $sectionCoords = Polyline::decodeFlexPolyline($section['polyline']);
                foreach ($sectionCoords as $c) {
                    $allCoords[] = $c;
                }
            }

            $summary = (isset($section['summary']) && is_array($section['summary'])) ? $section['summary'] : [];
            $legs[] = new RoutingLeg(
                distanceMeters: self::toFloat($summary['length'] ?? 0),
                durationSeconds: self::toFloat($summary['duration'] ?? 0),
            );
        }

        $totalDistance = array_sum(array_map(
            static fn(RoutingLeg $l): float => $l->distanceMeters,
            $legs,
        ));
        $totalDuration = array_sum(array_map(
            static fn(RoutingLeg $l): float => $l->durationSeconds,
            $legs,
        ));

        return new RoutingResult(
            legs: $legs,
            totalDistanceMeters: (float) $totalDistance,
            totalDurationSeconds: (float) $totalDuration,
            polyline: Polyline::encodePolyline($allCoords),
            waypointOrder: null,
            raw: $data,
        );
    }

    /**
     * First leg of the two-call optimization: `/v8/findsequence2`.
     *
     * Returns the absolute sequence of waypoint indices (origin first,
     * destination last, intermediates between).
     *
     * @param list<LatLng> $waypoints
     * @return list<int>
     */
    private function callFindSequence(array $waypoints, RoutingOptions $options): array
    {
        $first = $waypoints[0];
        $last = $waypoints[count($waypoints) - 1];
        $intermediates = array_slice($waypoints, 1, -1);

        $mode = sprintf('fastest;%s;traffic:disabled', $this->resolveTransportMode($options));

        $query = [
            'apiKey' => $this->config->apiKey,
            'start' => $first->toLatLngString(),
            'end' => $last->toLatLngString(),
            'mode' => $mode,
        ];

        if ($options->departureTime !== null) {
            $query['departureTime'] = $options->departureTime->format('c');
        }

        $url = self::SEQUENCE_URL;
        if ($intermediates !== []) {
            $destPairs = [];
            foreach ($intermediates as $i => $wp) {
                $destPairs[] = 'destination' . ($i + 1) . '=' . rawurlencode($wp->toLatLngString());
            }
            $url .= '?' . implode('&', $destPairs);
        }

        $response = $this->sendGet($url, [], $query);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response, 'HERE Waypoints Sequence');
        }

        $data = $this->decodeJson($response);
        $results = (is_array($data) && isset($data['results']) && is_array($data['results']))
            ? $data['results']
            : [];
        $sequenceResult = $results[0] ?? null;
        if (!is_array($sequenceResult) || !isset($sequenceResult['waypoints']) || !is_array($sequenceResult['waypoints'])) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'HERE findsequence2 returned no sequence',
                cause: $data,
            );
        }

        // Each waypoint: { id: 'start' | 'destinationN' | 'end', sequence: int }.
        /** @var list<array{id: string, sequence: int}> $entries */
        $entries = [];
        foreach ($sequenceResult['waypoints'] as $wp) {
            if (!is_array($wp) || !isset($wp['id'], $wp['sequence']) || !is_string($wp['id'])) {
                continue;
            }
            $seq = $wp['sequence'];
            $seqInt = is_int($seq) ? $seq : (is_numeric($seq) ? (int) $seq : null);
            if ($seqInt === null) {
                continue;
            }
            $entries[] = ['id' => $wp['id'], 'sequence' => $seqInt];
        }

        usort(
            $entries,
            /**
             * @param array{id: string, sequence: int} $a
             * @param array{id: string, sequence: int} $b
             */
            static fn(array $a, array $b): int => $a['sequence'] <=> $b['sequence'],
        );

        /** @var list<int> $absolute */
        $absolute = [];
        $lastIndex = count($waypoints) - 1;
        foreach ($entries as $entry) {
            $id = $entry['id'];
            if ($id === 'start') {
                $absolute[] = 0;
            } elseif ($id === 'end') {
                $absolute[] = $lastIndex;
            } elseif (str_starts_with($id, 'destination')) {
                // `destinationN` is 1-based into the original waypoint list.
                // Bounds-check it: a vendor response that names a waypoint
                // outside `[1, lastIndex - 1]` would otherwise index a
                // non-existent waypoint downstream (P4/C3-BH-9).
                $index = (int) substr($id, strlen('destination'));
                if ($index < 1 || $index > $lastIndex - 1) {
                    throw new ConnectorError(
                        statusCode: $response->getStatusCode(),
                        providerCode: ProviderCode::Unknown,
                        providerMessage: "HERE findsequence2 returned out-of-range waypoint id '{$id}'",
                        cause: $data,
                    );
                }
                $absolute[] = $index;
            }
        }

        return $absolute;
    }

    /**
     * Resolve the wire-level `transportMode` string.
     *
     * If the caller passed a {@see HereRoutingOptions} with a non-null
     * {@see HereTransportMode}, that overrides the base mapping.
     */
    private function resolveTransportMode(RoutingOptions $options): string
    {
        if ($options instanceof HereRoutingOptions && $options->transportMode !== null) {
            return $options->transportMode->value;
        }

        return $this->mapTransportMode($options->travelMode);
    }

    private function mapTransportMode(TravelMode $mode): string
    {
        return match ($mode) {
            TravelMode::Walking => 'pedestrian',
            TravelMode::Cycling => 'bicycle',
            TravelMode::Driving => 'car',
        };
    }

    private function buildAvoidFeatures(RoutingOptions $options): string
    {
        $avoids = [];
        if ($options->avoidTolls) {
            $avoids[] = 'tollRoad';
        }
        if ($options->avoidFerries) {
            $avoids[] = 'ferry';
        }
        if ($options->avoidHighways) {
            $avoids[] = 'controlledAccessHighway';
        }

        return implode(',', $avoids);
    }

    /**
     * Map vendor HTTP status + body shape to {@see ProviderCode}. Mirrors TS
     * Locality: per-connector.
     *
     * HERE classification is purely status-driven — the body is
     * accepted for signature parity with sibling connectors but not consulted.
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
            // HERE typically returns `{ title, cause, status }` for v8 errors.
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
