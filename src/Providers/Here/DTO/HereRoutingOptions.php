<?php

declare(strict_types=1);

namespace Thinwrap\Location\Providers\Here\DTO;

use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\Enum\TravelMode;
use Thinwrap\Location\Providers\Here\Enum\HereTransportMode;

/**
 * HERE-narrowed {@see RoutingOptions} adding an optional
 * {@see HereTransportMode} field.
 *
 *'s `RoutingOptionsMap['here']` module augmentation
 * pattern; in PHP we lean on a subclass + `instanceof` narrowing inside
 * {@see \Thinwrap\Location\Connector\Here\HereRoutingConnector::route}.
 *
 * When {@see $transportMode} is set, it takes precedence over the base
 * {@see TravelMode} mapping of.
 */
final readonly class HereRoutingOptions extends RoutingOptions
{
    public ?HereTransportMode $transportMode;

    /**
     * @param list<LatLng|array{0: float, 1: float}|array{lat: float, lng: float}> $waypoints
     */
    public function __construct(
        array $waypoints,
        bool $optimize = false,
        bool $optimizeFixedOrigin = false,
        bool $optimizeFixedDestination = false,
        bool $isRoundTrip = false,
        ?\DateTimeInterface $departureTime = null,
        bool $avoidTolls = false,
        bool $avoidFerries = false,
        bool $avoidHighways = false,
        TravelMode|string $travelMode = TravelMode::Driving,
        ?Passthrough $passthrough = null,
        HereTransportMode|string|null $transportMode = null,
    ) {
        parent::__construct(
            waypoints: $waypoints,
            optimize: $optimize,
            optimizeFixedOrigin: $optimizeFixedOrigin,
            optimizeFixedDestination: $optimizeFixedDestination,
            isRoundTrip: $isRoundTrip,
            departureTime: $departureTime,
            avoidTolls: $avoidTolls,
            avoidFerries: $avoidFerries,
            avoidHighways: $avoidHighways,
            travelMode: $travelMode,
            passthrough: $passthrough,
        );

        $this->transportMode = is_string($transportMode)
            ? HereTransportMode::from($transportMode)
            : $transportMode;
    }
}
