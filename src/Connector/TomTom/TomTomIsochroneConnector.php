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
use Thinwrap\Location\Contract\IsochroneConnectorInterface;
use Thinwrap\Location\DTO\Isochrone\IsochroneContour;
use Thinwrap\Location\DTO\Isochrone\IsochroneOptions;
use Thinwrap\Location\DTO\Isochrone\IsochroneResult;
use Thinwrap\Location\DTO\Passthrough as PassthroughDTO;
use Thinwrap\Location\Enum\IsochroneType;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Util\IsochroneValidator;
use Thinwrap\Location\Util\Passthrough;

/**
 * TomTom Reachable Range connector — PHP mirror of the TS connector
 *
 * Architectural outlier: TomTom's `calculateReachableRange` API accepts only
 * ONE budget value per call, so multi-band isochrones require N sequential
 * HTTP calls. the N-call assembly lives inside this connector.
 * This is the FIRST PHP connector to populate `result->_meta['requestCount']`
 * (present iff N > 1 calls, per the PINNED X2 contract).
 *
 * Wire shape:
 *   `GET https://api.tomtom.com/routing/1/calculateReachableRange/{lat},{lng}/json`
 * with `timeBudgetInSec` (for `type: 'time'`) or `distanceBudgetInMeters`
 * (for `type: 'distance'`), `travelMode`, optional `departAt`, and the
 * `key=` API-key query parameter.
 *
 * Sequential vs parallel: PHP can issue concurrent HTTP via `curl_multi_*`,
 * but the PSR-18 abstraction is single-request. v1.0 uses sequential
 * blocking PSR-18 calls — wall time scales linearly with `values.length`.
 * v1.x can add a parallel `MultiPsr18Client` adapter without touching this
 * connector's contract.
 *
 * Travel mode mapping with cycling — TomTom supports cycling natively:
 *   - `'driving'` → `car`.
 *   - `'walking'` → `pedestrian`.
 *   - `'cycling'` → `bicycle`.
 *
 * Fail-fast semantics: if ANY of the N calls fails, the whole
 * `isochrone()` throws the first {@see ConnectorError}; subsequent calls
 * are not dispatched.
 *
 * Boundary → Polygon: TomTom's `reachableRange.boundary` is an open
 * array of `{ latitude, longitude }` points; the connector emits a closed
 * ring of `[lng, lat]` pairs (GeoJSON convention).
 *
 * Cap: {@see IsochroneValidator::validateCap()} enforces the
 * 4-value ceiling at the top of `.isochrone()`.
 *
 * Retry-After surfacing: parsed seconds in `providerMessage` + raw header in
 * `cause.retryAfter` by design.
 */
final class TomTomIsochroneConnector extends BaseConnector implements IsochroneConnectorInterface
{
    private const REACHABLE_RANGE_URL = 'https://api.tomtom.com/routing/1/calculateReachableRange';

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

    public function isochrone(IsochroneOptions $options): IsochroneResult
    {
        IsochroneValidator::validateCap($options);
        // reject non-finite center before it reaches the URL path.
        $options->center->assertFinite('TomTom isochrone center');

        // fmtCoord (via format*()) keeps near-zero coords in fixed notation —
        // a raw "{$lat}" cast would emit "1.0E-5" for 0.00001, which TomTom
        // rejects in the path segment.
        $baseUrl = self::REACHABLE_RANGE_URL . '/'
            . $options->center->formatLat() . ',' . $options->center->formatLng() . '/json';
        $travelMode = $this->mapTravelMode($options->travelMode);

        $datas = [];
        $contours = [];
        foreach ($options->values as $value) {
            $data = $this->fetchOneBand($baseUrl, $value, $travelMode, $options);
            $datas[] = $data;

            if (
                !isset($data['reachableRange']) || !is_array($data['reachableRange'])
                || !isset($data['reachableRange']['boundary']) || !is_array($data['reachableRange']['boundary'])
            ) {
                // Mirror TS: a 2xx body without a `reachableRange.boundary` array
                // is malformed — throw rather than fabricate an empty Polygon.
                throw new ConnectorError(
                    statusCode: null,
                    providerCode: ProviderCode::Unknown,
                    providerMessage: 'Isochrone response had no boundary',
                    cause: $data,
                );
            }
            $boundary = $data['reachableRange']['boundary'];

            $contours[] = new IsochroneContour(
                value: $value,
                geometry: self::boundaryToPolygon($boundary),
            );
        }

        usort(
            $contours,
            static fn(IsochroneContour $a, IsochroneContour $b): int => $a->value <=> $b->value,
        );

        // X2 (PINNED): `_meta` is present iff MORE THAN ONE underlying HTTP call
        // was made. TomTom fans out one call per value, so single-value requests
        // OMIT `_meta` entirely (parity with the location-ts sibling).
        $requestCount = count($options->values);

        return new IsochroneResult(
            contours: $contours,
            raw: $datas,
            _meta: $requestCount > 1 ? ['requestCount' => $requestCount] : null,
        );
    }

    /**
     * Dispatch a single band; returns the decoded JSON body.
     *
     * @return array<string,mixed>
     */
    private function fetchOneBand(string $baseUrl, int|float $value, string $travelMode, IsochroneOptions $options): array
    {
        /** @var array<string,string|int|float|bool> $baseQuery */
        $baseQuery = [
            'key' => $this->config->apiKey,
            'travelMode' => $travelMode,
        ];

        if ($options->type === IsochroneType::Time) {
            $baseQuery['timeBudgetInSec'] = (string) $value;
        } else {
            $baseQuery['distanceBudgetInMeters'] = (string) $value;
        }

        if ($options->departureTime !== null) {
            $baseQuery['departAt'] = $options->departureTime->format(\DateTimeInterface::ATOM);
        }

        $merged = Passthrough::merge([], [], $baseQuery, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet($baseUrl, $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response);
        }

        $data = $this->decodeJson($response);
        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'TomTom Isochrone returned non-JSON body',
                message: 'TomTom Isochrone returned non-JSON body',
                cause: $data,
            );
        }

        return $data;
    }

    /**
     * Convert TomTom's open `boundary[]` of `{ latitude, longitude }` points
     * into a closed GeoJSON Polygon with `[lng, lat]` order.
     *
     * @param array<int,mixed> $boundary
     * @return array{type: string, coordinates: list<list<list<float>>>}
     */
    private static function boundaryToPolygon(array $boundary): array
    {
        /** @var list<list<float>> $ring */
        $ring = [];
        foreach ($boundary as $p) {
            if (!is_array($p)) {
                continue;
            }
            $lat = $p['latitude'] ?? null;
            $lng = $p['longitude'] ?? null;
            if (!is_int($lat) && !is_float($lat)) {
                continue;
            }
            if (!is_int($lng) && !is_float($lng)) {
                continue;
            }
            $ring[] = [(float) $lng, (float) $lat];
        }

        // Close the ring.
        if ($ring !== []) {
            $first = $ring[0];
            $ring[] = [$first[0], $first[1]];
        }

        return [
            'type' => 'Polygon',
            'coordinates' => [$ring],
        ];
    }

    private function mapTravelMode(?TravelMode $mode): string
    {
        // A null mode defers to the provider wire default (driving). Cycling
        // is rejected upstream at the IsochroneOptions DTO so it can never
        // reach here.
        return match ($mode) {
            TravelMode::Walking => 'pedestrian',
            TravelMode::Cycling => 'bicycle',
            TravelMode::Driving, null => 'car',
        };
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
            providerCode: $this->mapVendorError($status),
            providerMessage: $this->formatProviderMessage($body, $retryAfter),
            message: "TomTom Isochrone failed: {$status}",
            cause: $cause,
        );
    }

    /**
     * Map TomTom HTTP status → canonical {@see ProviderCode}.
     */
    private function mapVendorError(int $httpStatus): ProviderCode
    {
        if ($httpStatus === 400) {
            return ProviderCode::InvalidRequest;
        }
        if ($httpStatus === 401 || $httpStatus === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($httpStatus === 429) {
            return ProviderCode::RateLimited;
        }
        if ($httpStatus >= 500 && $httpStatus < 600) {
            return ProviderCode::ProviderUnavailable;
        }

        return ProviderCode::Unknown;
    }

    private function formatProviderMessage(mixed $body, ?string $retryAfter): ?string
    {
        $base = self::readTomTomErrorMessage($body);

        if ($retryAfter !== null && $retryAfter !== '' && is_numeric($retryAfter)) {
            $seconds = (int) $retryAfter;
            $suffix = "retry after {$seconds} seconds";

            return $base !== null ? "{$base}; {$suffix}" : $suffix;
        }

        return $base;
    }

    private static function readTomTomErrorMessage(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }
        $error = $body['error'] ?? null;
        if (is_array($error)) {
            $desc = $error['description'] ?? null;
            if (is_string($desc) && $desc !== '') {
                return $desc;
            }
            $msg = $error['message'] ?? null;
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
        }
        if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            return $body['message'];
        }
        if (is_string($error) && $error !== '') {
            return $error;
        }
        if (isset($body['detailedError']) && is_array($body['detailedError'])) {
            $msg = $body['detailedError']['message'] ?? null;
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
        }

        return null;
    }

    /**
     * Read + JSON-decode a response body. Returns null when empty or invalid JSON.
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
     * {@see Passthrough::merge()} accepts.
     *
     * @return array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string|int|float|bool>}|null
     */
    private function buildPassthroughBuckets(?PassthroughDTO $passthrough): ?array
    {
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
}
