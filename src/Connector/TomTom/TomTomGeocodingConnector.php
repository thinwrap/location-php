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
 * TomTom Search API Geocoding connector — PHP mirror of the TS connector
 *
 * Three path-form endpoints:
 *   - `GET https://api.tomtom.com/search/2/geocode/<query>.json`        — forward.
 *   - `GET https://api.tomtom.com/search/2/reverseGeocode/<lat>,<lon>.json` — reverse.
 *   - `GET https://api.tomtom.com/search/2/search/<query>.json`         — autocomplete
 *     (Fuzzy Search; TomTom's `/autocomplete` returns no coordinates so we
 *     use Search instead to surface coordinates + freeformAddress, mirroring
 * the TS sibling).
 *
 * Viewport conversion: TomTom returns
 * `viewport: { topLeftPoint: {lat,lon}, btmRightPoint: {lat,lon} }`. The
 * unified shape is south-west / north-east — topLeftPoint = (north, west),
 * btmRightPoint = (south, east).
 *
 * Retry-After surfacing: parsed seconds in `providerMessage` + raw header in
 * `cause.retryAfter` by design.
 */
final class TomTomGeocodingConnector extends BaseConnector implements GeocodingConnectorInterface
{
    private const GEOCODE_URL = 'https://api.tomtom.com/search/2/geocode';
    private const REVERSE_GEOCODE_URL = 'https://api.tomtom.com/search/2/reverseGeocode';
    private const SEARCH_URL = 'https://api.tomtom.com/search/2/search';

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

    public function geocode(GeocodeOptions $options): GeocodeResult
    {
        // reject empty/whitespace address in the path-form connectors only.
        // An empty segment corrupts the `/geocode/<address>.json` URL structure
        // (the general empty-query rejection is deferred to match ts #36).
        $this->assertNonEmptyQuery($options->address, 'TomTom Geocoding requires a non-empty address');

        // path-form URL construction.
        $url = self::GEOCODE_URL . '/' . rawurlencode($options->address) . '.json';

        /** @var array<string,string|int|float|bool> $query */
        $query = ['key' => $this->config->apiKey];

        if ($options->language !== null) {
            $query['language'] = $options->language;
        }
        // TomTom `countrySet=XX,YY` accepts a comma-separated list of
        // ISO 3166-1 alpha-2 codes for hard country filtering.
        if ($options->countryFilter !== null && $options->countryFilter !== []) {
            $query['countrySet'] = implode(',', $options->countryFilter);
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet($url, $merged['headers'], $merged['query']);

        $data = $this->decodeOrFail($response, 'TomTom Geocoding');

        /** @var array<int,mixed> $results */
        $results = (isset($data['results']) && is_array($data['results'])) ? $data['results'] : [];

        $candidates = [];
        foreach ($results as $r) {
            if (!is_array($r)) {
                continue;
            }
            $candidate = self::mapResultToCandidate($r);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return new GeocodeResult(candidates: $candidates, raw: $data);
    }

    public function reverseGeocode(ReverseGeocodeOptions $options): ReverseGeocodeResult
    {
        // Route coordinate formatting through the shared LatLng helper so the
        // `lat,lng` path segment matches the rest of the surface and avoids any
        // raw-float interpolation precision/scientific-notation drift.
        $url = self::REVERSE_GEOCODE_URL . '/' . $options->location->toLatLngString() . '.json';

        /** @var array<string,string|int|float|bool> $query */
        $query = ['key' => $this->config->apiKey];

        if ($options->language !== null) {
            $query['language'] = $options->language;
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet($url, $merged['headers'], $merged['query']);

        $data = $this->decodeOrFail($response, 'TomTom Reverse Geocoding');

        // TomTom returns `addresses[]`; map every entry to the shared
        // candidate shape. Each `addresses[i]` carries `address.freeformAddress`
        // and a `position` field that may be either `"lat,lon"` string or an
        // object `{lat, lon}` depending on response style.
        /** @var array<int,mixed> $addresses */
        $addresses = (isset($data['addresses']) && is_array($data['addresses'])) ? $data['addresses'] : [];

        $candidates = [];
        foreach ($addresses as $a) {
            if (!is_array($a)) {
                continue;
            }
            $candidate = self::mapAddressToCandidate($a);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return new ReverseGeocodeResult(candidates: $candidates, raw: $data);
    }

    public function autocomplete(AutocompleteOptions $options): AutocompleteResult
    {
        // reject empty/whitespace input in the path-form connector.
        $this->assertNonEmptyQuery($options->input, 'TomTom Autocomplete requires a non-empty input');

        // TomTom `/autocomplete` returns no coordinates; mirror the TS sibling's
        // choice of Fuzzy Search which serves as combined search + autocomplete
        // with coordinates + freeformAddress.
        $url = self::SEARCH_URL . '/' . rawurlencode($options->input) . '.json';

        /** @var array<string,string|int|float|bool> $query */
        $query = [
            'key' => $this->config->apiKey,
            'limit' => '5',
        ];

        if ($options->language !== null) {
            $query['language'] = $options->language;
        }
        if ($options->location !== null) {
            // reject non-finite before it reaches the query string.
            $options->location->assertFinite('TomTom autocomplete location');
            $query['lat'] = (string) $options->location->lat;
            $query['lon'] = (string) $options->location->lng;
        }
        if ($options->radius !== null) {
            $query['radius'] = (string) $options->radius;
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet($url, $merged['headers'], $merged['query']);

        $data = $this->decodeOrFail($response, 'TomTom Autocomplete');

        /** @var array<int,mixed> $results */
        $results = (isset($data['results']) && is_array($data['results'])) ? $data['results'] : [];

        $predictions = [];
        foreach ($results as $r) {
            if (!is_array($r)) {
                continue;
            }
            /** @var array<string,mixed> $address */
            $address = (isset($r['address']) && is_array($r['address'])) ? $r['address'] : [];
            $freeform = (isset($address['freeformAddress']) && is_string($address['freeformAddress']))
                ? $address['freeformAddress']
                : '';
            $description = $freeform;
            if (isset($r['poi']) && is_array($r['poi']) && isset($r['poi']['name']) && is_string($r['poi']['name'])) {
                $description = "{$r['poi']['name']}, {$freeform}";
            }
            $placeId = (isset($r['id']) && is_string($r['id']) && $r['id'] !== '') ? $r['id'] : null;
            $predictions[] = new AutocompletePrediction(
                description: $description,
                placeId: $placeId,
            );
        }

        return new AutocompleteResult(predictions: $predictions, raw: $data);
    }

    /**
     * Map a forward-geocode `results[i]` entry to a {@see GeocodeCandidate}.
     *
     * @param array<string,mixed> $r
     */
    private static function mapResultToCandidate(array $r): ?GeocodeCandidate
    {
        $position = (isset($r['position']) && is_array($r['position'])) ? $r['position'] : null;
        if ($position === null) {
            return null;
        }
        $lat = self::toNullableFloat($position['lat'] ?? null);
        $lon = self::toNullableFloat($position['lon'] ?? null);
        if ($lat === null || $lon === null) {
            return null;
        }

        /** @var array<string,mixed> $address */
        $address = (isset($r['address']) && is_array($r['address'])) ? $r['address'] : [];
        $formatted = (isset($address['freeformAddress']) && is_string($address['freeformAddress']))
            ? $address['freeformAddress']
            : '';

        $placeId = (isset($r['id']) && is_string($r['id']) && $r['id'] !== '') ? $r['id'] : null;

        // viewport conversion.
        // TomTom: viewport.topLeftPoint = (north, west); btmRightPoint = (south, east).
        // Unified:   southwest = (south, west); northeast = (north, east).
        $viewport = null;
        if (isset($r['viewport']) && is_array($r['viewport'])) {
            $vp = $r['viewport'];
            $tl = (isset($vp['topLeftPoint']) && is_array($vp['topLeftPoint'])) ? $vp['topLeftPoint'] : null;
            $br = (isset($vp['btmRightPoint']) && is_array($vp['btmRightPoint'])) ? $vp['btmRightPoint'] : null;
            if ($tl !== null && $br !== null) {
                $north = self::toNullableFloat($tl['lat'] ?? null);
                $west = self::toNullableFloat($tl['lon'] ?? null);
                $south = self::toNullableFloat($br['lat'] ?? null);
                $east = self::toNullableFloat($br['lon'] ?? null);
                if ($north !== null && $west !== null && $south !== null && $east !== null) {
                    $viewport = new Viewport(
                        southwest: new LatLng($south, $west),
                        northeast: new LatLng($north, $east),
                    );
                }
            }
        }

        return new GeocodeCandidate(
            formattedAddress: $formatted,
            location: new LatLng($lat, $lon),
            placeId: $placeId,
            viewport: $viewport,
        );
    }

    /**
     * Map a reverse-geocode `addresses[i]` entry to a {@see GeocodeCandidate}.
     * `position` may be either `"lat,lon"` string or object `{lat, lon}`.
     *
     * @param array<string,mixed> $a
     */
    private static function mapAddressToCandidate(array $a): ?GeocodeCandidate
    {
        /** @var array<string,mixed> $address */
        $address = (isset($a['address']) && is_array($a['address'])) ? $a['address'] : [];
        $formatted = (isset($address['freeformAddress']) && is_string($address['freeformAddress']))
            ? $address['freeformAddress']
            : '';

        $lat = null;
        $lng = null;
        if (isset($a['position'])) {
            $pos = $a['position'];
            if (is_string($pos)) {
                $parts = explode(',', $pos);
                if (count($parts) === 2) {
                    $lat = self::toNullableFloat($parts[0]);
                    $lng = self::toNullableFloat($parts[1]);
                }
            } elseif (is_array($pos)) {
                $lat = self::toNullableFloat($pos['lat'] ?? null);
                $lng = self::toNullableFloat($pos['lon'] ?? null);
            }
        }
        if ($lat === null || $lng === null) {
            return null;
        }

        $placeId = (isset($a['id']) && is_string($a['id']) && $a['id'] !== '') ? $a['id'] : null;

        return new GeocodeCandidate(
            formattedAddress: $formatted,
            location: new LatLng($lat, $lng),
            placeId: $placeId,
            viewport: null,
        );
    }

    /**
     * reject an empty/whitespace query for the path-form connectors, where
     * an empty segment corrupts the URL structure (vs. wasting a billable call).
     * The general empty-query rejection for query-param connectors is deferred
     * to match ts #36.
     */
    private function assertNonEmptyQuery(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: $label,
            );
        }
    }

    /**
     * Decode response on 2xx, raise {@see ConnectorError} otherwise.
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
     * Map TomTom HTTP status → canonical {@see ProviderCode}.
     */
    private function mapVendorError(int $httpStatus): ProviderCode
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
     */
    private function formatProviderMessage(mixed $body, ?string $retryAfter): ?string
    {
        $base = null;
        if (is_array($body)) {
            if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
                $base = $body['message'];
            } elseif (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
                $base = $body['error'];
            } elseif (
                isset($body['errorText']) && is_string($body['errorText']) && $body['errorText'] !== ''
            ) {
                $base = $body['errorText'];
            } elseif (
                isset($body['detailedError']) && is_array($body['detailedError'])
                && isset($body['detailedError']['message']) && is_string($body['detailedError']['message'])
                && $body['detailedError']['message'] !== ''
            ) {
                $base = $body['detailedError']['message'];
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
