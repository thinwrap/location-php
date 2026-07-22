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
 * Mapbox Isochrone v1 connector — PHP mirror of the TS connector
 *
 * GETs `https://api.mapbox.com/isochrone/v1/mapbox/{profile}/<lng>,<lat>` with
 * `contours_minutes`/`contours_meters`, `polygons=true`, and the
 * `access_token` query parameter.
 *
 * `polygons=true` invariant: re-stamped AFTER the `_passthrough` merge
 * so a consumer attempt to override it via `_passthrough.query.polygons` is
 * silently overwritten. Without it Mapbox returns LineString rings which do
 * not fit the unified Polygon shape.
 *
 * Travel mode mapping with cycling — Mapbox supports cycling natively:
 *   - `'driving'` → `mapbox/driving`.
 *   - `'walking'` → `mapbox/walking`.
 *   - `'cycling'` → `mapbox/cycling`.
 *
 * Values translation: `type: 'time'` divides input seconds by 60
 * (rounded) into `contours_minutes`; `type: 'distance'` passes input meters
 * through into `contours_meters`. Response `contour` values are converted
 * back to seconds when `type === 'time'` so contour `value` matches the
 * input unit.
 *
 * Cap: {@see IsochroneValidator::validateCap()} enforces the
 * 4-value ceiling at the top of `.isochrone()`.
 *
 * Retry-After surfacing: parsed seconds in `providerMessage` + raw header in
 * `cause.retryAfter` by design.
 */
final class MapboxIsochroneConnector extends BaseConnector implements IsochroneConnectorInterface
{
    private const ISOCHRONE_URL = 'https://api.mapbox.com/isochrone/v1/mapbox';

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

    public function isochrone(IsochroneOptions $options): IsochroneResult
    {
        IsochroneValidator::validateCap($options);
        // reject non-finite center before it reaches the URL path.
        $options->center->assertFinite('Mapbox isochrone center');

        $profile = $this->mapProfile($options->travelMode);
        // fmtCoord (via format*()) keeps near-zero coords in fixed notation —
        // a raw "{$lng}" cast would emit "1.0E-5" for 0.00001, which Mapbox
        // rejects in the path segment.
        $url = self::ISOCHRONE_URL . "/{$profile}/"
            . $options->center->formatLng() . ',' . $options->center->formatLat();

        /** @var array<string,string|int|float|bool> $baseQuery */
        $baseQuery = [
            'access_token' => $this->config->accessToken,
        ];

        if ($options->type === IsochroneType::Time) {
            $baseQuery['contours_minutes'] = implode(',', array_map(
                static fn(int|float $v): int => (int) round($v / 60),
                $options->values,
            ));
        } else {
            $baseQuery['contours_meters'] = implode(',', array_map(
                static fn(int|float $v): string => (string) $v,
                $options->values,
            ));
        }

        if ($options->departureTime !== null) {
            $baseQuery['depart_at'] = $options->departureTime->format(\DateTimeInterface::ATOM);
        }

        // Merge passthrough first, then re-stamp `polygons=true` so a consumer
        // attempt to override it via `_passthrough.query.polygons` is silently
        // overwritten (invariant).
        $merged = Passthrough::merge([], [], $baseQuery, $this->buildPassthroughBuckets($options->passthrough));
        $finalQuery = $merged['query'];
        $finalQuery['polygons'] = 'true';

        $response = $this->sendGet($url, $merged['headers'], $finalQuery);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response);
        }

        $data = $this->decodeJson($response);
        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'Mapbox Isochrone returned non-JSON body',
                message: 'Mapbox Isochrone returned non-JSON body',
                cause: $data,
            );
        }

        /** @var array<int,mixed> $features */
        $features = (isset($data['features']) && is_array($data['features'])) ? $data['features'] : [];

        $contours = [];
        foreach ($features as $f) {
            if (!is_array($f)) {
                continue;
            }
            $properties = (isset($f['properties']) && is_array($f['properties'])) ? $f['properties'] : [];
            $geometry = (isset($f['geometry']) && is_array($f['geometry'])) ? $f['geometry'] : null;
            if ($geometry === null) {
                continue;
            }

            $contour = $properties['contour'] ?? null;
            if (!is_int($contour) && !is_float($contour)) {
                continue;
            }
            // Mapbox returns the contour in the metric the caller requested
            // (minutes when contours_minutes was used). Convert back to seconds
            // so the contour `value` matches the input unit.
            $value = $options->type === IsochroneType::Time
                ? $contour * 60
                : $contour;

            // Pass the GeoJSON geometry through unchanged — Mapbox returns
            // Polygon coordinates in [lng, lat] order with closed rings.
            $contours[] = new IsochroneContour(
                value: $value,
                geometry: $geometry,
            );
        }

        usort(
            $contours,
            static fn(IsochroneContour $a, IsochroneContour $b): int => $a->value <=> $b->value,
        );

        return new IsochroneResult(contours: $contours, raw: $data);
    }

    private function mapProfile(?TravelMode $mode): string
    {
        // Null mode defers to the wire default (driving). Cycling is rejected
        // upstream at the IsochroneOptions DTO.
        return match ($mode) {
            TravelMode::Walking => 'walking',
            TravelMode::Cycling => 'cycling',
            TravelMode::Driving, null => 'driving',
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
            message: "Mapbox Isochrone failed: {$status}",
            cause: $cause,
        );
    }

    /**
     * Map Mapbox HTTP status → canonical {@see ProviderCode}.
     */
    private function mapVendorError(int $httpStatus): ProviderCode
    {
        if ($httpStatus === 401 || $httpStatus === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($httpStatus === 429) {
            return ProviderCode::RateLimited;
        }
        if ($httpStatus === 422 || $httpStatus === 400) {
            return ProviderCode::InvalidRequest;
        }
        if ($httpStatus >= 500 && $httpStatus < 600) {
            return ProviderCode::ProviderUnavailable;
        }

        return ProviderCode::Unknown;
    }

    private function formatProviderMessage(mixed $body, ?string $retryAfter): ?string
    {
        $base = null;
        if (is_array($body)) {
            if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
                $base = $body['message'];
            } elseif (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
                $base = $body['error'];
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
}
