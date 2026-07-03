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
 * Esri (ArcGIS) World Geocoding Service connector — PHP mirror of the TS connector
 *
 * Three endpoints under `GeocodeServer/`:
 *   - `findAddressCandidates` — forward geocoding (multi-result natively).
 *   - `reverseGeocode`        — reverse geocoding (single-result natively;
 * wrapped in a one-element `candidates[]`).
 *   - `suggest`               — autocomplete (`magicKey` → unified `placeId`).
 *
 * Dual-auth ({@see EsriConfig} `apiKey` XOR `arcgisToken`) is resolved via
 * {@see EsriConfig::bearerToken()} and forwarded as the `token=` query param.
 *
 * Esri's 200-with-error-body quirk: ArcGIS REST services frequently
 * return HTTP 200 OK with an `error: { code, message }` body for
 * application-layer failures. This connector inspects the body even on success
 * status and raises a {@see ConnectorError} via {@see EsriGeocodingConnector::mapVendorError()}.
 *
 * Retry-After surfacing: parsed seconds in `providerMessage` + raw header in
 * `cause.retryAfter` by design.
 *
 * Token lifecycle (~120 min for `arcgisToken`) is consumer-owned (the wrapper holds no state).
 */
final class EsriGeocodingConnector extends BaseConnector implements GeocodingConnectorInterface
{
    private const GEOCODE_URL = 'https://geocode-api.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates';
    private const REVGEOCODE_URL = 'https://geocode-api.arcgis.com/arcgis/rest/services/World/GeocodeServer/reverseGeocode';
    private const SUGGEST_URL = 'https://geocode-api.arcgis.com/arcgis/rest/services/World/GeocodeServer/suggest';

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

    public function geocode(GeocodeOptions $options): GeocodeResult
    {
        // forward geocode → findAddressCandidates with singleLine + token,
        // `outFields=*` to surface viewport `extent`, optional `countryCode`
        // from `countryFilter` (comma-joined alpha-2; Esri uses alpha-2 directly).
        /** @var array<string,string|int|float|bool> $query */
        $query = [
            'f' => 'json',
            'token' => $this->config->bearerToken(),
            'singleLine' => $options->address,
            'outFields' => '*',
        ];

        if ($options->language !== null) {
            $query['langCode'] = $options->language;
        }
        if ($options->countryFilter !== null && $options->countryFilter !== []) {
            $query['countryCode'] = implode(',', $options->countryFilter);
        }

        $data = $this->dispatchGet(self::GEOCODE_URL, $query, $options->passthrough, 'Esri Geocoding');

        /** @var array<int,mixed> $candidatesRaw */
        $candidatesRaw = (isset($data['candidates']) && is_array($data['candidates'])) ? $data['candidates'] : [];

        $candidates = [];
        foreach ($candidatesRaw as $c) {
            if (!is_array($c)) {
                continue;
            }
            $candidate = self::mapForwardCandidate($c);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return new GeocodeResult(candidates: $candidates, raw: $data);
    }

    public function reverseGeocode(ReverseGeocodeOptions $options): ReverseGeocodeResult
    {
        // reverse geocode → single result wrapped in candidates[].
        /** @var array<string,string|int|float|bool> $query */
        $query = [
            'f' => 'json',
            'token' => $this->config->bearerToken(),
            // Esri accepts `location=<lng>,<lat>` (lng-first per Esri x/y convention).
            'location' => $options->location->toLngLatString(),
        ];

        if ($options->language !== null) {
            $query['langCode'] = $options->language;
        }

        $data = $this->dispatchGet(self::REVGEOCODE_URL, $query, $options->passthrough, 'Esri Reverse Geocoding');

        // when Esri surfaces no address/location, return an empty
        // candidates[] (parity with the other 4 providers' empty result shape).
        $address = (isset($data['address']) && is_array($data['address'])) ? $data['address'] : null;
        $location = (isset($data['location']) && is_array($data['location'])) ? $data['location'] : null;
        if ($address === null || $location === null) {
            return new ReverseGeocodeResult(candidates: [], raw: $data);
        }

        $formatted = null;
        if (isset($address['LongLabel']) && is_string($address['LongLabel']) && $address['LongLabel'] !== '') {
            $formatted = $address['LongLabel'];
        } elseif (isset($address['Match_addr']) && is_string($address['Match_addr']) && $address['Match_addr'] !== '') {
            $formatted = $address['Match_addr'];
        }

        $y = self::toNullableFloat($location['y'] ?? null);
        $x = self::toNullableFloat($location['x'] ?? null);
        if ($formatted === null || $y === null || $x === null) {
            return new ReverseGeocodeResult(candidates: [], raw: $data);
        }

        // wrap: 1 element. `placeId` null (no stable opaque ID for
        // reverseGeocode); `viewport` null (Esri's reverse endpoint omits extent).
        $candidate = new GeocodeCandidate(
            formattedAddress: $formatted,
            location: new LatLng($y, $x),
            placeId: null,
            viewport: null,
        );

        return new ReverseGeocodeResult(candidates: [$candidate], raw: $data);
    }

    public function autocomplete(AutocompleteOptions $options): AutocompleteResult
    {
        // suggest → predictions. `radius` and `language` are documented
        // no-ops per Esri's `/suggest` surface.
        /** @var array<string,string|int|float|bool> $query */
        $query = [
            'f' => 'json',
            'token' => $this->config->bearerToken(),
            'text' => $options->input,
        ];

        if ($options->location !== null) {
            $query['location'] = $options->location->toLngLatString();
        }

        $data = $this->dispatchGet(self::SUGGEST_URL, $query, $options->passthrough, 'Esri Autocomplete');

        /** @var array<int,mixed> $suggestions */
        $suggestions = (isset($data['suggestions']) && is_array($data['suggestions'])) ? $data['suggestions'] : [];

        $predictions = [];
        foreach ($suggestions as $s) {
            if (!is_array($s)) {
                continue;
            }
            $text = (isset($s['text']) && is_string($s['text'])) ? $s['text'] : '';
            $magicKey = (isset($s['magicKey']) && is_string($s['magicKey']) && $s['magicKey'] !== '') ? $s['magicKey'] : null;
            $predictions[] = new AutocompletePrediction(
                description: $text,
                placeId: $magicKey,
            );
        }

        return new AutocompleteResult(predictions: $predictions, raw: $data);
    }

    /**
     * Shared GET dispatch + body inspection used by all three geocoding methods.
     * Funnels both HTTP-level errors and 200-with-error-body through
     * {@see EsriGeocodingConnector::raiseHttpError()} /
     * {@see EsriGeocodingConnector::raiseBodyError()}.
     *
     * @param array<string,string|int|float|bool> $query
     * @return array<string,mixed>
     */
    private function dispatchGet(string $url, array $query, ?PassthroughDTO $passthrough, string $label): array
    {
        $merged = Passthrough::merge([], [], $query, $this->buildPassthroughBuckets($passthrough));

        $response = $this->sendGet($url, $merged['headers'], $merged['query']);

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

        // Esri 200-with-error-body inspection.
        if (isset($data['error']) && is_array($data['error'])) {
            $this->raiseBodyError($data, $response->getStatusCode(), $label);
        }

        return $data;
    }

    /**
     * Map an Esri forward candidate to the unified {@see GeocodeCandidate}
     * shape. `viewport` derived from `extent`; `placeId` left null
     * (findAddressCandidates lacks a portable stable per-result identifier —
     * `attributes.UniqueID` is service-version-dependent).
     *
     * @param array<string,mixed> $c
     */
    private static function mapForwardCandidate(array $c): ?GeocodeCandidate
    {
        $location = (isset($c['location']) && is_array($c['location'])) ? $c['location'] : null;
        if ($location === null) {
            return null;
        }
        $y = self::toNullableFloat($location['y'] ?? null);
        $x = self::toNullableFloat($location['x'] ?? null);
        if ($y === null || $x === null) {
            return null;
        }

        $formatted = (isset($c['address']) && is_string($c['address'])) ? $c['address'] : '';

        $viewport = null;
        if (isset($c['extent']) && is_array($c['extent'])) {
            $extent = $c['extent'];
            $ymin = self::toNullableFloat($extent['ymin'] ?? null);
            $xmin = self::toNullableFloat($extent['xmin'] ?? null);
            $ymax = self::toNullableFloat($extent['ymax'] ?? null);
            $xmax = self::toNullableFloat($extent['xmax'] ?? null);
            if ($ymin !== null && $xmin !== null && $ymax !== null && $xmax !== null) {
                $viewport = new Viewport(
                    southwest: new LatLng($ymin, $xmin),
                    northeast: new LatLng($ymax, $xmax),
                );
            }
        }

        return new GeocodeCandidate(
            formattedAddress: $formatted,
            location: new LatLng($y, $x),
            placeId: null,
            viewport: $viewport,
        );
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
     * Raise a {@see ConnectorError} for Esri's 200-with-error-body case.
     *
     * @param array<string,mixed> $data
     */
    private function raiseBodyError(array $data, int $status, string $label): never
    {
        /** @var array<string,mixed> $err */
        $err = is_array($data['error'] ?? null) ? $data['error'] : [];
        $msg = (isset($err['message']) && is_string($err['message']) && $err['message'] !== '')
            ? $err['message']
            : (isset($err['code']) ? (string) $err['code'] : 'unknown');

        throw new ConnectorError(
            statusCode: $status,
            providerCode: $this->mapVendorError($status, $data),
            providerMessage: $this->formatProviderMessage($data, null),
            message: "{$label} failed: {$msg}",
            cause: $err,
        );
    }

    /**
     * Map Esri (HTTP status, decoded body) → canonical {@see ProviderCode}
     * Handles both HTTP-level codes and Esri's 200-with-error-body
     * case via `body.error.code`.
     */
    private function mapVendorError(int $httpStatus, mixed $body): ProviderCode
    {
        $bodyErrorCode = self::readBodyErrorCode($body);

        // Precedence fix (Esri 429-precedence): `429 → RateLimited` takes
        // precedence over the body-code → Unknown fallthrough, so a genuinely
        // rate-limited response carrying an ambiguous in-body error code still
        // classifies correctly. (The 200-with-error-body quirk is preserved:
        // a 200 status won't match this check, so in-body mapping still governs.)
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
     * Build the human-readable `providerMessage` from the vendor body, weaving
     * in parsed Retry-After seconds when present by design.
     */
    private function formatProviderMessage(mixed $body, ?string $retryAfter): ?string
    {
        $base = self::readEsriErrorMessage($body);

        if ($retryAfter !== null && $retryAfter !== '' && is_numeric($retryAfter)) {
            $seconds = (int) $retryAfter;
            $suffix = "retry after {$seconds} seconds";

            return $base !== null ? "{$base}; {$suffix}" : $suffix;
        }

        return $base;
    }

    private static function readBodyErrorCode(mixed $body): ?int
    {
        if (!is_array($body)) {
            return null;
        }
        $error = $body['error'] ?? null;
        if (!is_array($error)) {
            return null;
        }
        $code = $error['code'] ?? null;
        if (is_int($code)) {
            return $code;
        }
        if (is_float($code) && is_finite($code)) {
            return (int) $code;
        }
        if (is_string($code) && $code !== '' && is_numeric($code)) {
            return (int) $code;
        }

        return null;
    }

    private static function readEsriErrorMessage(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }
        $error = $body['error'] ?? null;
        if (is_array($error)) {
            $msg = $error['message'] ?? null;
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
            $code = $error['code'] ?? null;
            if (is_int($code) || is_float($code)) {
                return (string) $code;
            }
            if (is_string($code) && $code !== '') {
                return $code;
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
