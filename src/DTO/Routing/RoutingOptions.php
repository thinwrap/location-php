<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Routing;

use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\Enum\TravelMode;

/**
 * Unified routing request options.
 *
 * NOT `final` so the HERE-narrowed {@see \Thinwrap\Location\Providers\Here\DTO\HereRoutingOptions}
 * can extend it (the documented Passthrough+HERE-only narrowed-DTO pattern). Still
 * `readonly`.
 */
readonly class RoutingOptions
{
    /** @var list<LatLng> */
    public array $waypoints;
    public TravelMode $travelMode;

    /**
     * `optimizeFixedOrigin`/`optimizeFixedDestination` default to `false` to mirror the
     * TS `undefined`/falsy default: a default 3+-waypoint route no longer fires the
     * unrequested HERE `findsequence2` two-call optimization workflow. Optimization is
     * triggered only by an explicit `optimize` (or `isRoundTrip`) flag.
     *
     * @param list<LatLng|array{0: float, 1: float}|array{lat: float, lng: float}> $waypoints
     */
    public function __construct(
        array $waypoints,
        public bool $optimize = false,
        public bool $optimizeFixedOrigin = false,
        public bool $optimizeFixedDestination = false,
        public bool $isRoundTrip = false,
        public ?\DateTimeInterface $departureTime = null,
        public bool $avoidTolls = false,
        public bool $avoidFerries = false,
        public bool $avoidHighways = false,
        TravelMode|string $travelMode = TravelMode::Driving,
        public ?Passthrough $passthrough = null,
    ) {
        $this->waypoints = LatLng::fromList($waypoints);
        $this->travelMode = is_string($travelMode) ? TravelMode::from($travelMode) : $travelMode;
    }
}
