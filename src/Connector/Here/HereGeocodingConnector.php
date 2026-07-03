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
 * HERE Geocoding v7 connector — PHP mirror of the TS connector
 *
 * Three endpoints, three dispatch shapes:
 *   - `GET https://geocode.search.hereapi.com/v1/geocode`         — forward.
 *   - `GET https://revgeocode.search.hereapi.com/v1/revgeocode`    — reverse.
 *   - `GET https://autosuggest.search.hereapi.com/v1/autosuggest`  — autocomplete
 *     with optional proximity bias via `in=circle:<lat>,<lng>;r=<radius>` or
 *     `at=<lat>,<lng>` fallback.
 *
 * the per-connector ISO alpha-2 → alpha-3 translation lives inline
 * (no shared utility) — unmapped codes raise
 * {@see ConnectorError} with {@see ProviderCode::InvalidRequest}.
 *
 * Retry-After surfacing: parsed seconds in `providerMessage` and raw header
 * value in `cause.retryAfter` by design (no structured `retryAfterSeconds`).
 */
final class HereGeocodingConnector extends BaseConnector implements GeocodingConnectorInterface
{
    private const GEOCODE_URL = 'https://geocode.search.hereapi.com/v1/geocode';
    private const REVGEOCODE_URL = 'https://revgeocode.search.hereapi.com/v1/revgeocode';
    private const AUTOSUGGEST_URL = 'https://autosuggest.search.hereapi.com/v1/autosuggest';

    /**
     * ISO 3166-1 alpha-2 → alpha-3 country code translation.
     *
     * HERE Geocoding v7 expects alpha-3 codes in `in=countryCode:` whereas the
     * base {@see GeocodeOptions::$countryFilter} is alpha-2. Per
     * this map lives per-connector (no shared utility).
     *
     * Inlined to the 30 most common consumer countries; unmapped codes raise
     * `ConnectorError providerCode: invalid_request` and direct consumers to
     * `_passthrough.query.in` for the long tail.
     *
     * @var array<string,string>
     */
    private const ISO_ALPHA2_TO_ALPHA3 = [
        'US' => 'USA',
        'CA' => 'CAN',
        'GB' => 'GBR',
        'DE' => 'DEU',
        'FR' => 'FRA',
        'JP' => 'JPN',
        'CN' => 'CHN',
        'IN' => 'IND',
        'BR' => 'BRA',
        'AU' => 'AUS',
        'MX' => 'MEX',
        'IT' => 'ITA',
        'ES' => 'ESP',
        'NL' => 'NLD',
        'PL' => 'POL',
        'RU' => 'RUS',
        'KR' => 'KOR',
        'CH' => 'CHE',
        'SE' => 'SWE',
        'NO' => 'NOR',
        'DK' => 'DNK',
        'FI' => 'FIN',
        'IE' => 'IRL',
        'PT' => 'PRT',
        'AT' => 'AUT',
        'BE' => 'BEL',
        'GR' => 'GRC',
        'CZ' => 'CZE',
        'TR' => 'TUR',
        'SG' => 'SGP',
    ];

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

    public function geocode(GeocodeOptions $options): GeocodeResult
    {
        /** @var array<string,string|int|float|bool> $query */
        $query = [
            'q' => $options->address,
            'apiKey' => $this->config->apiKey,
        ];

        if ($options->language !== null) {
            $query['lang'] = $options->language;
        }
        // alpha-2 → alpha-3 translation; unmapped codes raise.
        if ($options->countryFilter !== null && $options->countryFilter !== []) {
            $alpha3 = [];
            foreach ($options->countryFilter as $code) {
                $key = strtoupper($code);
                if (!isset(self::ISO_ALPHA2_TO_ALPHA3[$key])) {
                    $msg = "HERE country code mapping unavailable for {$code}; please use _passthrough.query.in to pass HERE's alpha-3 directly.";

                    throw new ConnectorError(
                        statusCode: null,
                        providerCode: ProviderCode::InvalidRequest,
                        providerMessage: $msg,
                        message: $msg,
                    );
                }
                $alpha3[] = self::ISO_ALPHA2_TO_ALPHA3[$key];
            }
            $query['in'] = 'countryCode:' . implode(',', $alpha3);
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet(self::GEOCODE_URL, $merged['headers'], $merged['query']);

        $data = $this->decodeOrFail($response, 'HERE Geocoding');

        $candidates = $this->mapItems($data);

        return new GeocodeResult(candidates: $candidates, raw: $data);
    }

    public function reverseGeocode(ReverseGeocodeOptions $options): ReverseGeocodeResult
    {
        /** @var array<string,string|int|float|bool> $query */
        $query = [
            'at' => $options->location->toLatLngString(),
            'apiKey' => $this->config->apiKey,
        ];

        if ($options->language !== null) {
            $query['lang'] = $options->language;
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet(self::REVGEOCODE_URL, $merged['headers'], $merged['query']);

        $data = $this->decodeOrFail($response, 'HERE Reverse Geocoding');

        // HERE returns an `items[]` array even from /revgeocode; map
        // every entry instead of taking only the first.
        $candidates = $this->mapItems($data);

        return new ReverseGeocodeResult(candidates: $candidates, raw: $data);
    }

    public function autocomplete(AutocompleteOptions $options): AutocompleteResult
    {
        /** @var array<string,string|int|float|bool> $query */
        $query = [
            'q' => $options->input,
            'apiKey' => $this->config->apiKey,
            'limit' => '10',
        ];

        if ($options->language !== null) {
            $query['lang'] = $options->language;
        }
        // proximity bias.
        //   - `location` + `radius` → `in=circle:<lat>,<lng>;r=<radius>`.
        //   - `location` alone     → `at=<lat>,<lng>`.
        if ($options->location !== null) {
            // reject non-finite location before it reaches the query string.
            $options->location->assertFinite('HERE autocomplete location');
            if ($options->radius !== null) {
                $query['in'] = 'circle:' . $options->location->lat . ',' . $options->location->lng . ';r=' . $options->radius;
            } else {
                $query['at'] = $options->location->toLatLngString();
            }
        }

        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet(self::AUTOSUGGEST_URL, $merged['headers'], $merged['query']);

        $data = $this->decodeOrFail($response, 'HERE Autosuggest');

        /** @var array<int,mixed> $items */
        $items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : [];

        $predictions = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $description = '';
            if (isset($item['title']) && is_string($item['title'])) {
                $description = $item['title'];
            } elseif (
                isset($item['address']) && is_array($item['address'])
                && isset($item['address']['label']) && is_string($item['address']['label'])
            ) {
                $description = $item['address']['label'];
            }
            $placeId = (isset($item['id']) && is_string($item['id'])) ? $item['id'] : null;
            $predictions[] = new AutocompletePrediction(
                description: $description,
                placeId: $placeId,
            );
        }

        return new AutocompleteResult(predictions: $predictions, raw: $data);
    }

    /**
     * Map HERE `items[]` to canonical {@see GeocodeCandidate} list.
     *
     * @param array<string,mixed> $data
     * @return list<GeocodeCandidate>
     */
    private function mapItems(array $data): array
    {
        /** @var array<int,mixed> $items */
        $items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : [];

        $candidates = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $candidate = self::mapItemToCandidate($item);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Normalize a single HERE `items[]` entry to a {@see GeocodeCandidate}.
     *
     *   - `formattedAddress` ← `title` (or `address.label` fallback).
     *   - `location`         ← `position.{lat,lng}`.
     *   - `placeId`          ← `id` (HERE's locationId).
     *   - `viewport`         ← derived from `mapView` (south/west/north/east).
     *
     * @param array<string,mixed> $item
     */
    private static function mapItemToCandidate(array $item): ?GeocodeCandidate
    {
        $position = (isset($item['position']) && is_array($item['position'])) ? $item['position'] : null;
        if ($position === null) {
            return null;
        }
        $lat = self::toNullableFloat($position['lat'] ?? null);
        $lng = self::toNullableFloat($position['lng'] ?? null);
        if ($lat === null || $lng === null) {
            return null;
        }

        $formatted = '';
        if (isset($item['title']) && is_string($item['title']) && $item['title'] !== '') {
            $formatted = $item['title'];
        } elseif (
            isset($item['address']) && is_array($item['address'])
            && isset($item['address']['label']) && is_string($item['address']['label'])
        ) {
            $formatted = $item['address']['label'];
        }

        $placeId = (isset($item['id']) && is_string($item['id']) && $item['id'] !== '') ? $item['id'] : null;

        $viewport = null;
        if (isset($item['mapView']) && is_array($item['mapView'])) {
            $mv = $item['mapView'];
            $south = self::toNullableFloat($mv['south'] ?? null);
            $west = self::toNullableFloat($mv['west'] ?? null);
            $north = self::toNullableFloat($mv['north'] ?? null);
            $east = self::toNullableFloat($mv['east'] ?? null);
            if ($south !== null && $west !== null && $north !== null && $east !== null) {
                $viewport = new Viewport(
                    southwest: new LatLng($south, $west),
                    northeast: new LatLng($north, $east),
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
     * Map HERE HTTP status → canonical {@see ProviderCode}. * the mapping lives per-connector (no shared middleware).
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
     *
     * @param mixed $body Decoded vendor error body.
     */
    private function formatProviderMessage(mixed $body, ?string $retryAfter): ?string
    {
        $base = self::readHereErrorMessage($body);

        if ($retryAfter !== null && $retryAfter !== '' && is_numeric($retryAfter)) {
            $seconds = (int) $retryAfter;
            $suffix = "retry after {$seconds} seconds";

            return $base !== null ? "{$base}; {$suffix}" : $suffix;
        }

        return $base;
    }

    /**
     * Extract a best-effort message string from a HERE error body shape.
     *
     *   - HERE v8 errors: `{ title, cause, status }`.
     *   - Fallbacks: nested `{ error: { message } }`, top-level `message`,
     *     top-level `error`.
     */
    private static function readHereErrorMessage(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        $title = $body['title'] ?? null;
        $causeField = $body['cause'] ?? null;
        if (is_string($title) && $title !== '') {
            if (is_string($causeField) && $causeField !== '') {
                return "{$title}: {$causeField}";
            }

            return $title;
        }
        if (is_string($causeField) && $causeField !== '') {
            return $causeField;
        }

        $error = $body['error'] ?? null;
        if (is_array($error)) {
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
