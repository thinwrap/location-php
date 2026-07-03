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
use Thinwrap\Location\Contract\GeocodingConnectorInterface;
use Thinwrap\Location\DTO\Geocoding\AutocompleteOptions;
use Thinwrap\Location\DTO\Geocoding\AutocompletePrediction;
use Thinwrap\Location\DTO\Geocoding\AutocompleteResult;
use Thinwrap\Location\DTO\Geocoding\GeocodeCandidate;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\DTO\Geocoding\GeocodeResult;
use Thinwrap\Location\DTO\Geocoding\ReverseGeocodeOptions;
use Thinwrap\Location\DTO\Geocoding\ReverseGeocodeResult;
use Thinwrap\Location\DTO\Geocoding\Viewport;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough as PassthroughDTO;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Util\Passthrough;

/**
 * Mapbox Geocoding connector — PHP mirror of the TS connector
 *
 *   Forward / reverse:  Geocoding v6
 *     - `GET https://api.mapbox.com/search/geocode/v6/forward?q=...&country=...`
 *     - `GET https://api.mapbox.com/search/geocode/v6/reverse?longitude=...&latitude=...`
 *
 *   Autocomplete:  Searchbox `/suggest`
 *     - `GET https://api.mapbox.com/search/searchbox/v1/suggest?q=...&session_token=...`
 *
 * Searchbox requires a session token (Mapbox correlates `/suggest` → `/retrieve`
 * for per-session billing). The connector generates one per call via
 * `Ramsey\Uuid\Uuid::uuid4()->toString()` when `ramsey/uuid` is available, else
 * a `bin2hex(random_bytes(16))` fallback. Consumers can override per-request
 * with `_passthrough.query.session_token` — the passthrough merge is
 * last-write-wins so the consumer always wins.
 *
 * `radius` on {@see AutocompleteOptions} is a documented no-op for Mapbox
 * Searchbox (it has no first-class radius filter); use `_passthrough.query.proximity`
 * for proximity biasing.
 *
 * Response normalization for v6 forward/reverse:
 *   - `formattedAddress` ← `properties.full_address` ?? `properties.place_formatted` ?? `place_name`
 *   - `location`         ← `{ lat: geometry.coordinates[1], lng: geometry.coordinates[0] }`
 *   - `placeId`          ← `properties.mapbox_id`
 *   - `viewport`         ← from `properties.bbox` `[minLng, minLat, maxLng, maxLat]`
 *
 * Searchbox suggestion normalization:
 *   - `description` ← `full_address` ?? `name`
 *   - `placeId`     ← `mapbox_id`
 *
 * `mapVendorError` mirrors (Mapbox Routing): 401/403 →
 * `AuthFailed`, 422 → `InvalidRequest`, 429 → `RateLimited`, 5xx →
 * `ProviderUnavailable`, fallback → `Unknown`. Retry-After is surfaced as
 * parsed seconds in `providerMessage` and the raw header in `cause.retryAfter`
 * by design.
 */
final class MapboxGeocodingConnector extends BaseConnector implements GeocodingConnectorInterface
{
    private const FORWARD_URL = 'https://api.mapbox.com/search/geocode/v6/forward';
    private const REVERSE_URL = 'https://api.mapbox.com/search/geocode/v6/reverse';
    private const SUGGEST_URL = 'https://api.mapbox.com/search/searchbox/v1/suggest';

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

    public function geocode(GeocodeOptions $options): GeocodeResult
    {
        $query = [
            'q' => $options->address,
            'access_token' => $this->config->accessToken,
        ];

        if ($options->language !== null) {
            $query['language'] = $options->language;
        }
        // Mapbox accepts a `country=xx,yy` comma-separated list of
        // ISO 3166-1 alpha-2 codes (lowercase) as its hard country filter.
        if ($options->countryFilter !== null && $options->countryFilter !== []) {
            $query['country'] = strtolower(implode(',', $options->countryFilter));
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet(self::FORWARD_URL, $merged['headers'], $merged['query']);

        $data = $this->decodeOrFail($response, 'Mapbox Geocoding (forward)');

        $candidates = $this->mapFeatures($data);

        return new GeocodeResult(candidates: $candidates, raw: $data);
    }

    public function reverseGeocode(ReverseGeocodeOptions $options): ReverseGeocodeResult
    {
        $query = [
            // P13 follow-up: Mapbox v6 reverse needs SEPARATE longitude/latitude
            // params (not a combined "x,y"). Route through the per-axis formatters
            // (finiteness-guarded). Method names mirror the location-ts sibling.
            'longitude' => $options->location->formatLng(),
            'latitude' => $options->location->formatLat(),
            'access_token' => $this->config->accessToken,
        ];

        if ($options->language !== null) {
            $query['language'] = $options->language;
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet(self::REVERSE_URL, $merged['headers'], $merged['query']);

        $data = $this->decodeOrFail($response, 'Mapbox Geocoding (reverse)');

        // v6 returns a `features[]` ranked list; map every entry.
        $candidates = $this->mapFeatures($data);

        return new ReverseGeocodeResult(candidates: $candidates, raw: $data);
    }

    public function autocomplete(AutocompleteOptions $options): AutocompleteResult
    {
        $query = [
            'q' => $options->input,
            'access_token' => $this->config->accessToken,
            'session_token' => self::generateSessionToken(),
        ];

        if ($options->language !== null) {
            $query['language'] = $options->language;
        }
        if ($options->location !== null) {
            // Searchbox supports `proximity=lng,lat` for proximity biasing.
            $query['proximity'] = $options->location->toLngLatString();
        }

        // `radius` is a documented no-op for Searchbox — consumers can
        // override `proximity` via `_passthrough.query.proximity` if needed.
        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet(self::SUGGEST_URL, $merged['headers'], $merged['query']);

        $data = $this->decodeOrFail($response, 'Mapbox Geocoding (suggest)');

        $suggestions = (isset($data['suggestions']) && is_array($data['suggestions'])) ? $data['suggestions'] : [];

        $predictions = [];
        foreach ($suggestions as $s) {
            if (!is_array($s)) {
                continue;
            }
            $description = null;
            if (isset($s['full_address']) && is_string($s['full_address']) && $s['full_address'] !== '') {
                $description = $s['full_address'];
            } elseif (isset($s['name']) && is_string($s['name'])) {
                $description = $s['name'];
            }
            if ($description === null) {
                continue;
            }
            $placeId = null;
            if (isset($s['mapbox_id']) && is_string($s['mapbox_id'])) {
                $placeId = $s['mapbox_id'];
            }
            $predictions[] = new AutocompletePrediction(
                description: $description,
                placeId: $placeId,
            );
        }

        return new AutocompleteResult(predictions: $predictions, raw: $data);
    }

    /**
     * Map v6 `features[]` to canonical {@see GeocodeCandidate} list.
     *
     * @param array<string,mixed> $data
     * @return list<GeocodeCandidate>
     */
    private function mapFeatures(array $data): array
    {
        $features = (isset($data['features']) && is_array($data['features'])) ? $data['features'] : [];

        $candidates = [];
        foreach ($features as $f) {
            if (!is_array($f)) {
                continue;
            }
            $candidate = self::mapFeatureToCandidate($f);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Map a single v6 feature to a {@see GeocodeCandidate}.
     *
     * @param array<string,mixed> $f
     */
    private static function mapFeatureToCandidate(array $f): ?GeocodeCandidate
    {
        /** @var array<string,mixed> $properties */
        $properties = (isset($f['properties']) && is_array($f['properties'])) ? $f['properties'] : [];

        // prefer `properties.full_address`, then `properties.place_formatted`,
        // then top-level `place_name` (older response shape fallback).
        $formatted = null;
        if (isset($properties['full_address']) && is_string($properties['full_address']) && $properties['full_address'] !== '') {
            $formatted = $properties['full_address'];
        } elseif (isset($properties['place_formatted']) && is_string($properties['place_formatted']) && $properties['place_formatted'] !== '') {
            $formatted = $properties['place_formatted'];
        } elseif (isset($f['place_name']) && is_string($f['place_name'])) {
            $formatted = $f['place_name'];
        }
        if ($formatted === null) {
            return null;
        }

        // Coordinates: v6 puts them under `geometry.coordinates = [lng, lat]`.
        $lng = null;
        $lat = null;
        if (isset($f['geometry']) && is_array($f['geometry'])) {
            $coords = $f['geometry']['coordinates'] ?? null;
            if (is_array($coords) && array_key_exists(0, $coords) && array_key_exists(1, $coords)) {
                $lng = self::toNullableFloat($coords[0]);
                $lat = self::toNullableFloat($coords[1]);
            }
        }
        if ($lng === null || $lat === null) {
            return null;
        }

        $placeId = null;
        if (isset($properties['mapbox_id']) && is_string($properties['mapbox_id'])) {
            $placeId = $properties['mapbox_id'];
        }

        $viewport = null;
        if (isset($properties['bbox']) && is_array($properties['bbox']) && count($properties['bbox']) === 4) {
            /** @var array<int,mixed> $bbox */
            $bbox = array_values($properties['bbox']);
            $minLng = self::toNullableFloat($bbox[0]);
            $minLat = self::toNullableFloat($bbox[1]);
            $maxLng = self::toNullableFloat($bbox[2]);
            $maxLat = self::toNullableFloat($bbox[3]);
            if ($minLng !== null && $minLat !== null && $maxLng !== null && $maxLat !== null) {
                $viewport = new Viewport(
                    southwest: new LatLng($minLat, $minLng),
                    northeast: new LatLng($maxLat, $maxLng),
                );
            }
        }

        return new GeocodeCandidate(
            formattedAddress: $formatted,
            location: new LatLng($lat, $lng),
            placeId: $placeId,
            viewport: $viewport,
        );
    }

    /**
     * Generate a per-call Searchbox session token: an RFC 4122 v4 UUID built
     * from random_bytes() in pure PHP (no dependency), matching the UUID format
     * the TypeScript sibling emits. Mapbox accepts any non-empty opaque token
     * here per the Searchbox docs.
     */
    private static function generateSessionToken(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * Decode the response body, raise on non-2xx.
     *
     * @return array<string,mixed>
     */
    private function decodeOrFail(ResponseInterface $response, string $label): array
    {
        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response, $label);
        }

        $data = $this->decodeJson($response);
        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: "{$label} returned non-JSON body",
                message: "{$label} returned non-JSON body",
                cause: $data,
            );
        }

        return $data;
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
            providerCode: $this->mapVendorError($status),
            providerMessage: $this->formatProviderMessage($body, $retryAfter),
            message: "{$label} failed: {$status}",
            cause: $cause,
        );
    }

    /**
     * Map vendor HTTP status to {@see ProviderCode}. 7
     * (shared-Mapbox shape). Locality: per-connector.
     */
    private function mapVendorError(int $httpStatus): ProviderCode
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
            } elseif (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
                $base = $body['error'];
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

    private static function toNullableFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
