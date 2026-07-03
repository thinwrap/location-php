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
use Thinwrap\Location\Contract\MatrixConnectorInterface;
use Thinwrap\Location\DTO\Matrix\MatrixCell;
use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\DTO\Matrix\MatrixResult;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Util\Passthrough;

/**
 * Esri (ArcGIS) OD Cost Matrix connector — PHP mirror of the TS connector
 *
 * Posts to
 * https://logistics.arcgis.com/arcgis/rest/services/World/OriginDestinationCostMatrix/NAServer/OriginDestinationCostMatrix_World/solveODCostMatrix
 * with `origins` and `destinations` as pipe-separated `lng,lat,id` triplets
 * (1-based IDs per ESRI convention; mirrors the wire format,
 *). Dual-auth (`apiKey` xor `arcgisToken`) is resolved via
 * {@see EsriConfig::bearerToken()} (PHP-ESRI pattern).
 *
 * ESRI's signature 200-with-error-body quirk (shared with routing): the
 * service returns HTTP 200 OK with an `error: { code, message }`
 * body for application-layer failures (invalid token, malformed query). This
 * connector inspects the body even on success status codes and throws a
 * {@see ConnectorError} for either path.
 *
 * Result-shape conversion: `odLines.features[]` carries 1-based
 * `OriginOID` / `DestinationOID` plus `Total_Distance` (miles) and
 * `Total_Time` (minutes). The connector decrements indices and converts to
 * meters/seconds. Retry-After surfaces via parsed seconds in providerMessage
 * plus raw header in `cause.retryAfter` (no structured field by design).
 */
final class EsriMatrixConnector extends BaseConnector implements MatrixConnectorInterface
{
    private const MATRIX_URL = 'https://logistics.arcgis.com/arcgis/rest/services/World/OriginDestinationCostMatrix/NAServer/OriginDestinationCostMatrix_World/solveODCostMatrix';
    private const MILES_TO_METERS = 1609.344;
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

    public function matrix(MatrixOptions $options): MatrixResult
    {
        $originCount = count($options->origins);
        $destinationCount = count($options->destinations);

        if ($originCount === 0 || $destinationCount === 0) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Esri Matrix requires at least one origin and one destination',
            );
        }

        $originsParam = $this->encodeStops($options->origins);
        $destinationsParam = $this->encodeStops($options->destinations);

        // Esri matrix now READS travelMode (previously dropped — walking
        // never reached the wire and cycling silently degraded to driving).
        // `walking` → `'Walking'`; `cycling` → throw `unsupported_travel_mode`;
        // `driving` → omit the field (ESRI default).
        $travelMode = self::mapTravelMode($options->travelMode);

        $form = [
            'f' => 'json',
            'token' => $this->config->bearerToken(),
            'origins' => $originsParam,
            'destinations' => $destinationsParam,
            'outputType' => 'esriNAODOutputStraightLine',
        ];

        if ($travelMode !== null) {
            $form['travelMode'] = $travelMode;
        }

        if ($options->avoidTolls) {
            $form['restrictionAttributeNames'] = 'Avoid Toll Roads';
        }

        // Passthrough body overlays form fields; headers/query overlay too.
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

        $response = $this->sendPostForm(self::MATRIX_URL, $formData, $merged['headers'], $merged['query']);

        $data = $this->decodeJson($response);

        // body inspection regardless of HTTP status (200-with-error-body).
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
                providerMessage: 'Esri Matrix returned non-JSON body',
                cause: $data,
            );
        }

        return $this->normalizeSuccess($data);
    }

    /**
     * Encode a list of stops as ESRI's pipe-separated `lng,lat,id` triplets
     * with 1-based IDs.
     *
     * @param list<\Thinwrap\Location\DTO\LatLng> $stops
     */
    private function encodeStops(array $stops): string
    {
        $parts = [];
        foreach ($stops as $i => $stop) {
            $parts[] = "{$stop->lng},{$stop->lat}," . ($i + 1);
        }

        return implode(';', $parts);
    }

    /**
     * Normalize a 2xx ESRI response into a {@see MatrixResult}.
     *
     * `odLines.features[]` carries 1-based OIDs (subtract 1) and totals in
     * miles (× 1609.344) / minutes (× 60).
     *
     * @param array<string, mixed> $data
     */
    private function normalizeSuccess(array $data): MatrixResult
    {
        $odLines = (isset($data['odLines']) && is_array($data['odLines'])) ? $data['odLines'] : [];
        $features = (isset($odLines['features']) && is_array($odLines['features'])) ? $odLines['features'] : [];

        $cells = [];
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $attrs = (isset($feature['attributes']) && is_array($feature['attributes'])) ? $feature['attributes'] : [];

            $originOid = self::toInt($attrs['OriginOID'] ?? 0);
            $destOid = self::toInt($attrs['DestinationOID'] ?? 0);
            // Skip features with missing/non-numeric OIDs (< 1, ESRI is 1-based)
            // rather than emitting a fabricated `-1`-indexed cell.
            if ($originOid < 1 || $destOid < 1) {
                continue;
            }
            $totalDistanceMiles = self::toFloat($attrs['Total_Distance'] ?? 0);
            $totalTimeMinutes = self::toFloat($attrs['Total_Time'] ?? 0);

            $cells[] = new MatrixCell(
                originIndex: $originOid - 1,
                destinationIndex: $destOid - 1,
                distanceMeters: $totalDistanceMiles * self::MILES_TO_METERS,
                durationSeconds: $totalTimeMinutes * self::MINUTES_TO_SECONDS,
            );
        }

        return new MatrixResult(cells: $cells, raw: $data);
    }

    /**
     * Map vendor HTTP status + decoded body shape to {@see ProviderCode}.
     * 9 ESRI Routing table — handles both HTTP-level
     * codes and Esri's 200-with-error-body case via `body.error.code`.
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

        // Precedence fix (CR pre-existing): `429 → RateLimited` takes precedence
        // over the body-code → Unknown fallthrough, so a rate-limited response
        // carrying an in-body error code still classifies correctly.
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
            message: "Esri Matrix failed: {$status}",
            cause: $cause,
        );
    }

    /**
     * Throw a {@see ConnectorError} for a 200-OK ESRI response carrying an
     * `error` body (shared with routing pattern).
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
            message: "Esri Matrix returned error body: {$status}",
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
    private function buildPassthroughBuckets(MatrixOptions $options): ?array
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

    /**
     * Map {@see TravelMode} → ESRI matrix travel-mode name (mirrors TS).
     * `driving` → null (omit field, ESRI default); `walking` → `'Walking'`;
     * `cycling` → throw `unsupported_travel_mode` (ESRI World OD Cost Matrix
     * ships no public cycling mode).
     */
    private static function mapTravelMode(TravelMode $mode): ?string
    {
        return match ($mode) {
            TravelMode::Walking => 'Walking',
            TravelMode::Cycling => throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::UnsupportedTravelMode,
                providerMessage: 'ESRI Matrix does not support travelMode "cycling"',
            ),
            TravelMode::Driving => null,
        };
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

    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
