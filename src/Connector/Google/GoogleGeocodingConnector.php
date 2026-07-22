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
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Util\Passthrough;

/**
 * Google Geocoding connector — PHP mirror of the TS connector
 *
 * Three operations on three different surfaces:
 *   - `geocode()`         — GET `maps/api/geocode/json?address=…&components=country:XX|country:YY`
 *   - `reverseGeocode()`  — GET `maps/api/geocode/json?latlng=lat,lng`
 *   - `autocomplete()`    — POST `https://places.googleapis.com/v1/places:autocomplete`
 *                            (Places Autocomplete NEW API; header-auth with `X-Goog-Api-Key`).
 *
 * Body shape, `mapVendorError` table and Retry-After surfacing follow the
 * Story-3.6 per-connector template established by {@see GoogleRoutingConnector}.
 * Outlier `countryFilter → components=country:XX|country:YY` translation lives
 * here (per-connector outlier locality).
 */
final class GoogleGeocodingConnector extends BaseConnector implements GeocodingConnectorInterface
{
    private const GEOCODE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';
    private const AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';

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

    public function geocode(GeocodeOptions $options): GeocodeResult
    {
        /** @var array<string, string|int|float|bool> $query */
        $query = [
            'address' => $options->address,
            'key' => $this->config->apiKey,
        ];

        if ($options->language !== null) {
            $query['language'] = $options->language;
        }
        // map countryFilter (ISO 3166-1 alpha-2 list) to
        // Google's `components=country:XX|country:YY` hard-filter parameter.
        // Mechanical per-connector outlier translation.
        if ($options->countryFilter !== null && $options->countryFilter !== []) {
            // Validate each entry as ISO 3166-1 alpha-2 before building the
            // `|`-delimited `components` value, so a stray delimiter (e.g.
            // 'US|CA') cannot corrupt the structural query. Mirrors TS.
            foreach ($options->countryFilter as $cc) {
                if (preg_match('/^[A-Za-z]{2}$/', $cc) !== 1) {
                    throw new ConnectorError(
                        statusCode: null,
                        providerCode: ProviderCode::InvalidRequest,
                        providerMessage: "Invalid countryFilter entry: {$cc} (expected ISO 3166-1 alpha-2)",
                        message: "Invalid countryFilter entry: {$cc} (expected ISO 3166-1 alpha-2)",
                    );
                }
            }
            $query['components'] = implode(
                '|',
                array_map(static fn(string $cc): string => 'country:' . $cc, $options->countryFilter),
            );
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet(self::GEOCODE_URL, $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response, 'Google Geocoding failed');
        }

        $data = $this->decodeJson($response);
        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'Google Geocoding returned non-JSON body',
                cause: $data,
            );
        }
        $this->checkGoogleBodyStatus($response->getStatusCode(), $data, 'Google Geocoding failed');

        $results = (isset($data['results']) && is_array($data['results'])) ? $data['results'] : [];

        $candidates = [];
        foreach ($results as $r) {
            if (is_array($r)) {
                $candidate = self::mapResultToCandidate($r);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        return new GeocodeResult(candidates: $candidates, raw: $data);
    }

    public function reverseGeocode(ReverseGeocodeOptions $options): ReverseGeocodeResult
    {
        // reject non-finite location before it reaches the query string.
        $options->location->assertFinite('Google reverseGeocode');

        /** @var array<string, string|int|float|bool> $query */
        $query = [
            // toLatLngString() forces fixed-point notation; raw float concat emits
            // scientific notation for near-zero coords (e.g. -0.00005 → "-5.0E-5"),
            // which Google rejects.
            'latlng' => $options->location->toLatLngString(),
            'key' => $this->config->apiKey,
        ];

        if ($options->language !== null) {
            $query['language'] = $options->language;
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet(self::GEOCODE_URL, $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response, 'Google Reverse Geocoding failed');
        }

        $data = $this->decodeJson($response);
        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'Google Reverse Geocoding returned non-JSON body',
                cause: $data,
            );
        }
        $this->checkGoogleBodyStatus($response->getStatusCode(), $data, 'Google Reverse Geocoding failed');

        // reverse-geocode mirrors forward shape — return all candidates,
        // not just the first result. Google natively returns `results[]`.
        $results = (isset($data['results']) && is_array($data['results'])) ? $data['results'] : [];

        $candidates = [];
        foreach ($results as $r) {
            if (is_array($r)) {
                $candidate = self::mapResultToCandidate($r);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        return new ReverseGeocodeResult(candidates: $candidates, raw: $data);
    }

    public function autocomplete(AutocompleteOptions $options): AutocompleteResult
    {
        // Places Autocomplete NEW API: POST + JSON body + header auth.
        /** @var array<string, mixed> $body */
        $body = ['input' => $options->input];

        if ($options->language !== null) {
            $body['languageCode'] = $options->language;
        }
        if ($options->location !== null) {
            // reject non-finite locationBias center.
            $options->location->assertFinite('Google autocomplete');
            $circle = [
                'center' => [
                    'latitude' => $options->location->lat,
                    'longitude' => $options->location->lng,
                ],
            ];
            if ($options->radius !== null) {
                $circle['radius'] = $options->radius;
            }
            $body['locationBias'] = ['circle' => $circle];
        }

        /** @var array<string, string> $headers */
        $headers = [
            'X-Goog-Api-Key' => $this->config->apiKey,
        ];

        $merged = Passthrough::merge($body, $headers, [], $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendPostJson(self::AUTOCOMPLETE_URL, $merged['body'], $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response, 'Google Autocomplete failed');
        }

        $data = $this->decodeJson($response);

        // Places New API returns `{ suggestions: [{ placePrediction: { placeId, text: { text } } }] }`.
        $suggestions = (is_array($data) && isset($data['suggestions']) && is_array($data['suggestions']))
            ? $data['suggestions']
            : [];

        $predictions = [];
        foreach ($suggestions as $s) {
            if (!is_array($s) || !isset($s['placePrediction']) || !is_array($s['placePrediction'])) {
                continue;
            }
            $pp = $s['placePrediction'];
            $description = '';
            if (isset($pp['text']) && is_array($pp['text']) && isset($pp['text']['text']) && is_string($pp['text']['text'])) {
                $description = $pp['text']['text'];
            }
            $placeId = (isset($pp['placeId']) && is_string($pp['placeId'])) ? $pp['placeId'] : null;
            $predictions[] = new AutocompletePrediction(
                description: $description,
                placeId: $placeId,
            );
        }

        return new AutocompleteResult(predictions: $predictions, raw: $data);
    }

    /**
     * Map a Google `results[i]` element to a normalized {@see GeocodeCandidate}.
     *
     * Returns null (the caller skips) when `geometry.location.lat`/`lng` are
     * absent or non-numeric — never fabricate a Null-Island (0,0) candidate.
     * Mirrors the null-skip idiom of the other four geocoding connectors.
     *
     * @param array<string, mixed> $r
     */
    private static function mapResultToCandidate(array $r): ?GeocodeCandidate
    {
        $geometry = (isset($r['geometry']) && is_array($r['geometry'])) ? $r['geometry'] : [];

        $locationNode = (isset($geometry['location']) && is_array($geometry['location'])) ? $geometry['location'] : [];
        $lat = self::toNullableFloat($locationNode['lat'] ?? null);
        $lng = self::toNullableFloat($locationNode['lng'] ?? null);
        if ($lat === null || $lng === null) {
            return null;
        }
        $location = new LatLng($lat, $lng);

        $viewport = null;
        if (isset($geometry['viewport']) && is_array($geometry['viewport'])) {
            $vp = $geometry['viewport'];
            $sw = (isset($vp['southwest']) && is_array($vp['southwest'])) ? $vp['southwest'] : null;
            $ne = (isset($vp['northeast']) && is_array($vp['northeast'])) ? $vp['northeast'] : null;
            if ($sw !== null && $ne !== null) {
                $swLat = self::toNullableFloat($sw['lat'] ?? null);
                $swLng = self::toNullableFloat($sw['lng'] ?? null);
                $neLat = self::toNullableFloat($ne['lat'] ?? null);
                $neLng = self::toNullableFloat($ne['lng'] ?? null);
                if ($swLat !== null && $swLng !== null && $neLat !== null && $neLng !== null) {
                    $viewport = new Viewport(
                        southwest: new LatLng($swLat, $swLng),
                        northeast: new LatLng($neLat, $neLng),
                    );
                }
            }
        }

        $formatted = (isset($r['formatted_address']) && is_string($r['formatted_address'])) ? $r['formatted_address'] : '';
        $placeId = (isset($r['place_id']) && is_string($r['place_id'])) ? $r['place_id'] : null;

        return new GeocodeCandidate(
            formattedAddress: $formatted,
            location: $location,
            placeId: $placeId,
            viewport: $viewport,
        );
    }

    /**
     * Combine HTTP status + Google's in-body `status` field to derive a
     * normalized {@see ProviderCode}. *
     * @param mixed $body Decoded vendor body (may be null/scalar/array).
     */
    private function mapVendorError(int $httpStatus, mixed $body): ProviderCode
    {
        $googleStatus = null;
        if (is_array($body) && isset($body['status']) && is_string($body['status'])) {
            $googleStatus = $body['status'];
        }

        // Consult known in-body Google statuses FIRST, unconditionally (mirrors
        // TS), so e.g. HTTP 403 + body OVER_QUERY_LIMIT maps to RateLimited
        // rather than AuthFailed.
        if ($googleStatus === 'REQUEST_DENIED') {
            return ProviderCode::AuthFailed;
        }
        if ($googleStatus === 'OVER_QUERY_LIMIT') {
            return ProviderCode::RateLimited;
        }
        if ($googleStatus === 'INVALID_REQUEST') {
            return ProviderCode::InvalidRequest;
        }

        if ($httpStatus === 401) {
            return ProviderCode::AuthFailed;
        }
        if ($httpStatus === 403) {
            return $googleStatus === 'QUOTA_EXCEEDED' ? ProviderCode::RateLimited : ProviderCode::AuthFailed;
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
     * @param mixed $body Decoded vendor body.
     */
    private function formatProviderMessage(mixed $body, ?string $retryAfter): ?string
    {
        $base = null;
        if (is_array($body)) {
            // Geocoding API: top-level `error_message`.
            if (isset($body['error_message']) && is_string($body['error_message']) && $body['error_message'] !== '') {
                $base = $body['error_message'];
            } elseif (isset($body['error']) && is_array($body['error'])) {
                $msg = $body['error']['message'] ?? null;
                if (is_string($msg) && $msg !== '') {
                    $base = $msg;
                }
            } elseif (isset($body['status']) && is_string($body['status']) && $body['status'] !== 'OK') {
                $base = 'Google API returned status: ' . $body['status'];
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
     * Inspect the decoded 2xx body's `status` field. Successful values are
     * `'OK'` and `'ZERO_RESULTS'` (the latter yields an empty candidates list).
     * Any other status raises a {@see ConnectorError}.
     *
     * @param mixed $body Decoded body.
     */
    private function checkGoogleBodyStatus(int $httpStatus, mixed $body, string $opLabel): void
    {
        $status = null;
        if (is_array($body) && isset($body['status']) && is_string($body['status'])) {
            $status = $body['status'];
        }
        if ($status === null || $status === 'OK' || $status === 'ZERO_RESULTS') {
            return;
        }

        throw new ConnectorError(
            statusCode: $httpStatus,
            providerCode: $this->mapVendorError($httpStatus, $body),
            providerMessage: $this->formatProviderMessage($body, null),
            message: "{$opLabel}: {$status}",
            cause: $body,
        );
    }

    /**
     * Parse the response body + raise a {@see ConnectorError} for non-2xx.
     */
    private function raiseHttpError(ResponseInterface $response, string $opLabel): never
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
            message: "{$opLabel}: {$status}",
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
     * Convert the typed `Passthrough` DTO into the loose array shape that
     * {@see Passthrough::merge()} accepts.
     *
     * @return array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string|int|float|bool>}|null
     */
    private function buildPassthroughBuckets(?\Thinwrap\Location\DTO\Passthrough $passthrough): ?array
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

    /**
     * Parse a coordinate float, returning null (rather than 0.0) for an absent
     * or non-numeric value so {@see mapResultToCandidate()} can skip the
     * candidate instead of fabricating a Null-Island (0,0) location.
     */
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
