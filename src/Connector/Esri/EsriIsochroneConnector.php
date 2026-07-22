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
use Thinwrap\Location\Contract\IsochroneConnectorInterface;
use Thinwrap\Location\DTO\Isochrone\IsochroneContour;
use Thinwrap\Location\DTO\Isochrone\IsochroneOptions;
use Thinwrap\Location\DTO\Isochrone\IsochroneResult;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough as PassthroughDTO;
use Thinwrap\Location\Enum\IsochroneType;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Util\IsochroneValidator;
use Thinwrap\Location\Util\Passthrough;

/**
 * Esri (ArcGIS) ServiceArea connector — PHP mirror of the TS connector
 *
 * POSTs form-encoded data (NOT JSON — mirrors sibling 1.28 wire shape) to the
 * World ServiceArea `solveServiceArea` endpoint with the center point as an
 * ESRI `facilities` FeatureSet
 * (`{ features: [{ geometry: { x: lng, y: lat, spatialReference: { wkid: 4326 } } }] }`).
 *
 * Dual-auth: {@see EsriConfig} `apiKey` XOR `arcgisToken` is
 * resolved via {@see EsriConfig::bearerToken()} and forwarded as the `token`
 * form field.
 *
 * Values conversion: for `type: 'time'` input seconds are divided by
 * 60 (rounded) into `defaultBreaks` minutes — ESRI's native unit when
 * `breakUnits = esriDriveTimeUnitsMinutes`. For `type: 'distance'` input
 * meters are passed through with `esriDriveDistanceUnitsMeters`.
 *
 * Travel mode: base `'driving'` uses the ESRI default (no
 * `travelMode` field); `'walking'` embeds the canonical World "Walking Time"
 * travel-mode object (see {@see EsriTravelModes::map()}) — ArcGIS requires a full
 * JSON object, not a name string. ESRI does NOT carry cycling at the base level.
 *
 * Response normalization: ESRI returns
 * `saPolygons.features[i].geometry.rings: number[][][]` already in
 * `[lng, lat]` order when `outSR=4326`. v1.0 takes the outer ring (`rings[0]`)
 * and converts `attributes.ToBreak` back to the input unit (minutes →
 * seconds when `type: 'time'`).
 *
 * 200-with-error-body quirk: ArcGIS surfaces app-level failures as
 * 200 + `{ error: { code, message } }`. The connector inspects body on success
 * status and funnels both paths through {@see EsriIsochroneConnector::mapVendorError()}.
 *
 * Cap: {@see IsochroneValidator::validateCap()} enforces the 4-value
 * ceiling at the top of `.isochrone()`.
 *
 * Retry-After surfacing: parsed seconds in `providerMessage` + raw header in
 * `cause.retryAfter` by design.
 */
final class EsriIsochroneConnector extends BaseConnector implements IsochroneConnectorInterface
{
    private const SERVICE_AREA_URL = 'https://route-api.arcgis.com/arcgis/rest/services/World/ServiceAreas/NAServer/ServiceArea_World/solveServiceArea';
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

    public function isochrone(IsochroneOptions $options): IsochroneResult
    {
        IsochroneValidator::validateCap($options);
        // reject non-finite center before it reaches the wire.
        $options->center->assertFinite('Esri isochrone center');

        $facilities = self::buildFacilitiesFeatureSet($options->center);

        // ESRI native units: minutes for time, meters for distance.
        if ($options->type === IsochroneType::Time) {
            // ESRI accepts FRACTIONAL minutes, so convert seconds losslessly
            // rather than rounding to whole minutes (which corrupted sub-minute
            // breaks: 30s → 1 min → 60s, and 20s → 0). Trim to 6 decimals only to
            // strip float noise.
            $breaks = implode(',', array_map(
                static fn(int|float $v): string => rtrim(rtrim(number_format($v / 60, 6, '.', ''), '0'), '.'),
                $options->values,
            ));
            $breakUnits = 'esriDriveTimeUnitsMinutes';
        } else {
            $breaks = implode(',', array_map(
                static fn(int|float $v): string => (string) $v,
                $options->values,
            ));
            $breakUnits = 'esriDriveDistanceUnitsMeters';
        }

        /** @var array<string,mixed> $form */
        $form = [
            'f' => 'json',
            'token' => $this->config->bearerToken(),
            'facilities' => json_encode($facilities, JSON_THROW_ON_ERROR),
            'defaultBreaks' => $breaks,
            'breakUnits' => $breakUnits,
            'outputPolygons' => 'esriNAOutputPolygonDetailed',
            'returnFacilities' => 'false',
            'travelDirection' => 'esriNATravelDirectionFromFacility',
            'outSR' => '4326',
        ];

        $travelMode = EsriTravelModes::map($options->travelMode, 'Isochrone');
        if ($travelMode !== null) {
            $form['travelMode'] = $travelMode;
        }

        if ($options->departureTime !== null) {
            // ESRI accepts epoch milliseconds for `timeOfDay`.
            $form['timeOfDay'] = (string) ($options->departureTime->getTimestamp() * 1000);
        }

        // Passthrough.body overlays form fields; headers/query overlay as usual.
        $merged = Passthrough::merge($form, [], [], $this->buildPassthroughBuckets($options->passthrough));

        /** @var array<string,string> $formData */
        $formData = [];
        foreach ($merged['body'] as $key => $value) {
            $formData[$key] = self::stringifyFormValue($value);
        }

        $response = $this->sendPostForm(self::SERVICE_AREA_URL, $formData, $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response);
        }

        $data = $this->decodeJson($response);
        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'ESRI Isochrone returned non-JSON body',
                message: 'ESRI Isochrone returned non-JSON body',
                cause: $data,
            );
        }

        // 200-with-error-body inspection.
        if (isset($data['error']) && is_array($data['error'])) {
            $this->raiseBodyError($response->getStatusCode(), $data);
        }

        /** @var array<string,mixed> $saPolygons */
        $saPolygons = (isset($data['saPolygons']) && is_array($data['saPolygons'])) ? $data['saPolygons'] : [];
        /** @var array<int,mixed> $features */
        $features = (isset($saPolygons['features']) && is_array($saPolygons['features'])) ? $saPolygons['features'] : [];

        $contours = [];
        foreach ($features as $f) {
            if (!is_array($f)) {
                continue;
            }
            $attrs = (isset($f['attributes']) && is_array($f['attributes'])) ? $f['attributes'] : [];
            $geometry = (isset($f['geometry']) && is_array($f['geometry'])) ? $f['geometry'] : null;
            if ($geometry === null) {
                continue;
            }
            /** @var array<int,mixed> $rings */
            $rings = (isset($geometry['rings']) && is_array($geometry['rings'])) ? $geometry['rings'] : [];
            $outerRing = $rings[0] ?? [];
            if (!is_array($outerRing)) {
                $outerRing = [];
            }

            $toBreak = $attrs['ToBreak'] ?? null;
            if (!is_int($toBreak) && !is_float($toBreak)) {
                continue;
            }
            // ESRI returns the break value in the unit we requested. Convert
            // back to the caller's input unit so `value` matches input.
            $value = $options->type === IsochroneType::Time
                ? $toBreak * self::MINUTES_TO_SECONDS
                : $toBreak;

            $contours[] = new IsochroneContour(
                value: $value,
                geometry: [
                    'type' => 'Polygon',
                    'coordinates' => [$outerRing],
                ],
            );
        }

        usort(
            $contours,
            static fn(IsochroneContour $a, IsochroneContour $b): int => $a->value <=> $b->value,
        );

        return new IsochroneResult(contours: $contours, raw: $data);
    }

    /**
     * @return array{features: list<array{geometry: array{x: float, y: float, spatialReference: array{wkid: int}}}>}
     */
    private static function buildFacilitiesFeatureSet(LatLng $center): array
    {
        return [
            'features' => [
                [
                    'geometry' => [
                        'x' => $center->lng,
                        'y' => $center->lat,
                        'spatialReference' => ['wkid' => 4326],
                    ],
                ],
            ],
        ];
    }

    private static function stringifyFormValue(mixed $value): string
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
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_THROW_ON_ERROR);
        }

        return (string) $value;
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
            message: "ESRI Isochrone failed: {$status}",
            cause: $cause,
        );
    }

    /**
     * Raise a {@see ConnectorError} for ESRI's 200-with-error-body case.
     *
     * @param array<string,mixed> $data
     */
    private function raiseBodyError(int $status, array $data): never
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
            message: "ESRI Isochrone failed: {$msg}",
            cause: $err,
        );
    }

    /**
     * Map ESRI (HTTP status, decoded body) → canonical {@see ProviderCode}.
     * Handles both HTTP-level codes and ESRI's 200-with-error-body case.
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
}
