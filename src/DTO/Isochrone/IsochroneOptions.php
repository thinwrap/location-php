<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Isochrone;

use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\Enum\IsochroneType;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;

/**
 * Unified Isochrone request options.
 *
 * `departureTime` is promoted to a first-class field on the base shape:
 * every Isochrone provider that supports time-aware isolines
 * honors it, OSRM/Google fall outside the support matrix so they don't
 * carry the burden.
 *
 * `travelMode` is narrowed at the base shape to `Driving | Walking`
 * (honesty correction). Cycling is supported by Mapbox + TomTom
 * Isochrone offerings but missing from HERE/Esri, so it cannot live on
 * the unified facade contract. The enum itself still exposes all three
 * cases for use inside the Mapbox/TomTom connectors via `_passthrough`
 * or provider-specific options.
 *
 * `cycling` is REJECTED at this DTO — the constructor throws
 * a typed {@see ConnectorError} (`unsupported_travel_mode`) so cycling can never
 * reach an isochrone connector (single chokepoint). `travelMode` is optional
 * (`?TravelMode`, default `null`) to mirror the TS `travelMode?: 'driving' |
 * 'walking'` shape; a `null` mode lets each connector apply its own wire default
 * (driving). To reach Mapbox/TomTom cycling profiles, use `_passthrough`.
 */
final readonly class IsochroneOptions
{
    public LatLng $center;
    public IsochroneType $type;
    public ?TravelMode $travelMode;

    /**
     * @param LatLng|array{0: float, 1: float}|array{lat: float, lng: float} $center
     * @param list<int|float> $values Time in seconds or distance in meters; capped at 4 per request.
     * @param TravelMode|string|null $travelMode
     *     Optional (TS parity). Only `driving`/`walking` are supported on the
     *     unified facade — `cycling` is REJECTED at runtime with
     * `unsupported_travel_mode`. Pass `_passthrough` to reach
     *     Mapbox/TomTom cycling profiles. A `null` mode defers to the provider's
     *     wire default (driving).
     */
    public function __construct(
        LatLng|array $center,
        IsochroneType|string $type,
        public array $values,
        TravelMode|string|null $travelMode = null,
        public ?\DateTimeInterface $departureTime = null,
        public ?Passthrough $passthrough = null,
    ) {
        $this->center = LatLng::from($center);
        $this->type = is_string($type) ? IsochroneType::from($type) : $type;

        $mode = is_string($travelMode) ? TravelMode::from($travelMode) : $travelMode;
        if ($mode === TravelMode::Cycling) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::UnsupportedTravelMode,
                providerMessage: "Isochrone travelMode 'cycling' is not supported by the unified facade (only driving/walking). Use _passthrough for Mapbox/TomTom cycling profiles.",
            );
        }
        $this->travelMode = $mode;
    }
}
