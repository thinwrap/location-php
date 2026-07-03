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
use Thinwrap\Location\Contract\IsochroneConnectorInterface;
use Thinwrap\Location\DTO\Isochrone\IsochroneContour;
use Thinwrap\Location\DTO\Isochrone\IsochroneOptions;
use Thinwrap\Location\DTO\Isochrone\IsochroneResult;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough as PassthroughDTO;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Util\IsochroneValidator;
use Thinwrap\Location\Util\Passthrough;
use Thinwrap\Location\Util\Polyline;

/**
 * HERE Isolines v8 connector — PHP mirror of the TS connector
 *
 * GETs `https://isoline.router.hereapi.com/v8/isolines` with `origin`,
 * `range[type]`, `range[values]`, `transportMode`, and the `apiKey` query
 * parameter.
 *
 * Travel mode mapping: the base shape only carries `Driving | Walking`
 * — HERE does not offer cycling on the Isoline API. `'driving'` →
 * `'car'`; `'walking'` → `'pedestrian'`. Cycling slips in via
 * `_passthrough.query.transportMode` if a consumer needs it.
 *
 * Range params: native units (seconds for time, meters for distance)
 * — no conversion.
 *
 * Response normalization: HERE returns each isoline's outer ring as
 * a flexible-polyline string; {@see Polyline::decodeFlexPolyline()} (Story
 * 3.4) decodes it to `LatLng[]`, and the connector closes the GeoJSON Polygon
 * ring by appending the first coordinate when the boundary is not already
 * closed. Holes (`polygons[j].inner[]`) are ignored at v1.0.
 *
 * Cap: {@see IsochroneValidator::validateCap()} enforces the
 * 4-value ceiling at the top of `.isochrone()`.
 *
 * Retry-After surfacing: parsed seconds in `providerMessage` + raw header in
 * `cause.retryAfter` by design.
 */
final class HereIsochroneConnector extends BaseConnector implements IsochroneConnectorInterface
{
    private const ISOLINE_URL = 'https://isoline.router.hereapi.com/v8/isolines';

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

    public function isochrone(IsochroneOptions $options): IsochroneResult
    {
        IsochroneValidator::validateCap($options);

        $transportMode = $this->mapTransportMode($options->travelMode);

        /** @var array<string,string|int|float|bool> $baseQuery */
        $baseQuery = [
            'apiKey' => $this->config->apiKey,
            'origin' => $options->center->toLatLngString(),
            'range[type]' => $options->type->value,
            'range[values]' => implode(',', array_map(
                static fn(int|float $v): string => (string) $v,
                $options->values,
            )),
            'transportMode' => $transportMode,
        ];

        if ($options->departureTime !== null) {
            $baseQuery['departureTime'] = $options->departureTime->format(\DateTimeInterface::ATOM);
        }

        $merged = Passthrough::merge([], [], $baseQuery, $this->buildPassthroughBuckets($options->passthrough));

        $response = $this->sendGet(self::ISOLINE_URL, $merged['headers'], $merged['query']);

        if ($response->getStatusCode() >= 300) {
            $this->raiseHttpError($response);
        }

        $data = $this->decodeJson($response);
        if (!is_array($data)) {
            throw new ConnectorError(
                statusCode: $response->getStatusCode(),
                providerCode: ProviderCode::Unknown,
                providerMessage: 'HERE Isochrone returned non-JSON body',
                message: 'HERE Isochrone returned non-JSON body',
                cause: $data,
            );
        }

        /** @var array<int,mixed> $isolines */
        $isolines = (isset($data['isolines']) && is_array($data['isolines'])) ? $data['isolines'] : [];

        $contours = [];
        foreach ($isolines as $iso) {
            if (!is_array($iso)) {
                continue;
            }
            $range = (isset($iso['range']) && is_array($iso['range'])) ? $iso['range'] : null;
            if ($range === null) {
                continue;
            }
            $rangeValue = $range['value'] ?? null;
            if (!is_int($rangeValue) && !is_float($rangeValue)) {
                continue;
            }

            // only outer ring of polygons[0]; holes ignored at v1.0.
            $polygons = (isset($iso['polygons']) && is_array($iso['polygons'])) ? $iso['polygons'] : [];
            $first = $polygons[0] ?? null;
            $outerFlex = null;
            if (is_array($first) && isset($first['outer']) && is_string($first['outer'])) {
                $outerFlex = $first['outer'];
            }

            $coords = $outerFlex !== null ? Polyline::decodeFlexPolyline($outerFlex) : [];

            /** @var list<list<float>> $ring */
            $ring = array_map(
                static fn(LatLng $c): array => [$c->lng, $c->lat],
                $coords,
            );

            // Close the ring if not already closed (GeoJSON requires it).
            if ($ring !== []) {
                $firstPt = $ring[0];
                $lastPt = $ring[count($ring) - 1];
                if ($firstPt[0] !== $lastPt[0] || $firstPt[1] !== $lastPt[1]) {
                    $ring[] = [$firstPt[0], $firstPt[1]];
                }
            }

            $contours[] = new IsochroneContour(
                value: $rangeValue,
                geometry: [
                    'type' => 'Polygon',
                    'coordinates' => [$ring],
                ],
            );
        }

        usort(
            $contours,
            static fn(IsochroneContour $a, IsochroneContour $b): int => $a->value <=> $b->value,
        );

        return new IsochroneResult(contours: $contours, raw: $data);
    }

    private function mapTransportMode(?TravelMode $mode): string
    {
        // Null mode defers to the wire default (driving). Cycling is rejected
        // upstream at the IsochroneOptions DTO.
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
            message: "HERE Isochrone failed: {$status}",
            cause: $cause,
        );
    }

    /**
     * Map HERE HTTP status → canonical {@see ProviderCode}.
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
        if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            return $body['message'];
        }
        $error = $body['error'] ?? null;
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
