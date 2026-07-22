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
use Thinwrap\Location\Util\Passthrough;

/**
 * Esri (ArcGIS) OD Cost Matrix connector — PHP mirror of the TS connector
 *
 * Posts to
 * https://route-api.arcgis.com/arcgis/rest/services/World/OriginDestinationCostMatrix/NAServer/OriginDestinationCostMatrix_World/solveODCostMatrix
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
 * Result-shape conversion: the primary `esriNAODOutputSparseMatrix` response
 * maps each 1-based origin OID to `{ <destOID>: [values...] }` alongside a
 * `costAttributeNames` list naming the value columns; the connector reads
 * `TravelTime` (minutes → × 60 s) and `Kilometers` (km → × 1000 m). An
 * `odLines.features[]` straight-lines response is parsed as a fallback via
 * 1-based `OriginID` / `DestinationID` + `Total_TravelTime` (minutes) /
 * `Total_Kilometers` (km). Retry-After surfaces via parsed seconds in
 * providerMessage plus raw header in `cause.retryAfter` (no structured field
 * by design).
 */
final class EsriMatrixConnector extends BaseConnector implements MatrixConnectorInterface
{
    private const MATRIX_URL = 'https://route-api.arcgis.com/arcgis/rest/services/World/OriginDestinationCostMatrix/NAServer/OriginDestinationCostMatrix_World/solveODCostMatrix';
    private const KM_TO_METERS = 1000;
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

        $originsParam = $this->encodeStops($options->origins, 'Esri matrix origin');
        $destinationsParam = $this->encodeStops($options->destinations, 'Esri matrix destination');

        // `walking` embeds the canonical World "Walking Time" JSON object (a bare
        // name string is ignored by ArcGIS); `cycling` throws
        // `unsupported_travel_mode`; `driving` omits the field (ESRI default).
        $travelMode = EsriTravelModes::map($options->travelMode, 'Matrix');

        $form = [
            'f' => 'json',
            'token' => $this->config->bearerToken(),
            'origins' => $originsParam,
            'destinations' => $destinationsParam,
            // TravelTime impedance (minutes) is auto-included in the output;
            // accumulate Kilometers to surface road distance. The sparse
            // matrix is the primary output shape (mirrors the go/py/ts libs).
            'impedanceAttributeName' => 'TravelTime',
            'accumulateAttributeNames' => 'Kilometers',
            'outputType' => 'esriNAODOutputSparseMatrix',
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

        return $this->normalizeSuccess($data, $originCount, $destinationCount);
    }

    /**
     * Encode a list of stops as ESRI's pipe-separated `lng,lat,id` triplets
     * with 1-based IDs.
     *
     * @param list<\Thinwrap\Location\DTO\LatLng> $stops
     * @param string $context finiteness-error label (e.g. "Esri matrix origin")
     */
    private function encodeStops(array $stops, string $context): string
    {
        $parts = [];
        foreach ($stops as $i => $stop) {
            // reject NaN/INF before interpolation — a non-finite float in
            // "{$stop->lng}" would emit the literal "NAN"/"INF" onto the wire
            // instead of raising the unified InvalidRequest ConnectorError.
            $stop->assertFinite($context);
            // formatLng/Lat force fixed-point notation; string interpolation of the
            // raw floats emits scientific notation for near-zero coords.
            $parts[] = $stop->formatLng() . ',' . $stop->formatLat() . ',' . ($i + 1);
        }

        return implode(';', $parts);
    }

    /**
     * Normalize a 2xx ESRI response into a {@see MatrixResult}.
     *
     * Prefers the primary `esriNAODOutputSparseMatrix` shape (`odCostMatrix`)
     * and falls back to the `odLines.features[]` straight-lines shape. Both
     * decrement 1-based OIDs to 0-based indices and convert TravelTime minutes
     * → seconds (× 60) and Kilometers → meters (× 1000).
     *
     * @param array<string, mixed> $data
     */
    private function normalizeSuccess(array $data, int $originCount, int $destinationCount): MatrixResult
    {
        if (isset($data['odCostMatrix']) && is_array($data['odCostMatrix'])) {
            return $this->normalizeSparseMatrix($data['odCostMatrix'], $data, $originCount, $destinationCount);
        }

        return $this->normalizeOdLines($data, $originCount, $destinationCount);
    }

    /**
     * Parse the primary `esriNAODOutputSparseMatrix` output.
     *
     * `odCostMatrix` maps each 1-based origin OID to
     * `{ <destOID>: [values in costAttributeNames order] }`, plus a
     * `costAttributeNames` sibling listing the value columns. This connector
     * requests impedance `TravelTime` (minutes) and accumulated `Kilometers`,
     * so it locates those columns by name: duration = TravelTime × 60 s,
     * distance = Kilometers × 1000 m. OIDs outside the requested dimensions or
     * non-numeric are skipped rather than emitting fabricated cells.
     *
     * @param array<array-key, mixed> $odCostMatrix
     * @param array<string, mixed> $data
     */
    private function normalizeSparseMatrix(array $odCostMatrix, array $data, int $originCount, int $destinationCount): MatrixResult
    {
        $names = (isset($odCostMatrix['costAttributeNames']) && is_array($odCostMatrix['costAttributeNames']))
            ? array_values($odCostMatrix['costAttributeNames'])
            : [];
        // The impedance column is named after the active travel mode: driving
        // reports `TravelTime`, walking reports `WalkTime` (the WALK travelMode
        // object overrides the requested `impedanceAttributeName`). Locate it by
        // the known time-impedance names rather than assuming `TravelTime`, else a
        // walking matrix silently decodes every duration as 0.
        $timeIdx = false;
        foreach (EsriTravelModes::TIME_ATTRIBUTE_NAMES as $candidate) {
            $idx = array_search($candidate, $names, true);
            if ($idx !== false) {
                $timeIdx = $idx;
                break;
            }
        }
        $distIdx = array_search('Kilometers', $names, true);

        $cells = [];
        foreach ($odCostMatrix as $originKey => $destinations) {
            if ($originKey === 'costAttributeNames' || !is_array($destinations)) {
                continue;
            }
            $originOid = self::toInt($originKey);
            if ($originOid < 1 || $originOid > $originCount) {
                continue;
            }
            foreach ($destinations as $destKey => $values) {
                if (!is_array($values)) {
                    continue;
                }
                $destOid = self::toInt($destKey);
                if ($destOid < 1 || $destOid > $destinationCount) {
                    continue;
                }
                $timeMinutes = ($timeIdx !== false && isset($values[$timeIdx])) ? self::toFloat($values[$timeIdx]) : 0.0;
                $distKm = ($distIdx !== false && isset($values[$distIdx])) ? self::toFloat($values[$distIdx]) : 0.0;

                $cells[] = new MatrixCell(
                    originIndex: $originOid - 1,
                    destinationIndex: $destOid - 1,
                    distanceMeters: $distKm * self::KM_TO_METERS,
                    durationSeconds: $timeMinutes * self::MINUTES_TO_SECONDS,
                );
            }
        }

        return new MatrixResult(cells: $cells, raw: $data);
    }

    /**
     * Parse the `esriNAODOutputStraightLines` fallback output.
     *
     * `odLines.features[]` carries 1-based `OriginID` / `DestinationID` and
     * totals `Total_TravelTime` (minutes) / `Total_Kilometers` (km). OIDs
     * outside the requested dimensions or non-numeric are skipped.
     *
     * @param array<string, mixed> $data
     */
    private function normalizeOdLines(array $data, int $originCount, int $destinationCount): MatrixResult
    {
        $odLines = (isset($data['odLines']) && is_array($data['odLines'])) ? $data['odLines'] : [];
        $features = (isset($odLines['features']) && is_array($odLines['features'])) ? $odLines['features'] : [];

        $cells = [];
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $attrs = (isset($feature['attributes']) && is_array($feature['attributes'])) ? $feature['attributes'] : [];

            $originId = self::toInt($attrs['OriginID'] ?? 0);
            $destId = self::toInt($attrs['DestinationID'] ?? 0);
            if ($originId < 1 || $originId > $originCount || $destId < 1 || $destId > $destinationCount) {
                continue;
            }

            $totalKilometers = self::toFloat($attrs['Total_Kilometers'] ?? 0);
            $totalTimeMinutes = self::toFloat($attrs['Total_TravelTime'] ?? 0);

            $cells[] = new MatrixCell(
                originIndex: $originId - 1,
                destinationIndex: $destId - 1,
                distanceMeters: $totalKilometers * self::KM_TO_METERS,
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
