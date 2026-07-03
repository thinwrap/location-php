<?php

declare(strict_types=1);

namespace Thinwrap\Location\Providers\Here\Enum;

/**
 * HERE-specific transport modes for the Routing v8 API.
 *
 * `HereTransportMode` union literal. Used by
 * {@see \Thinwrap\Location\Providers\Here\DTO\HereRoutingOptions} to narrow
 * the base {@see \Thinwrap\Location\Enum\TravelMode} mapping with HERE's
 * extended vehicle set of.
 *
 * Backing string values match HERE's wire-format `transportMode` enum.
 */
enum HereTransportMode: string
{
    case Car = 'car';
    case Truck = 'truck';
    case Pedestrian = 'pedestrian';
    case Bicycle = 'bicycle';
    case Scooter = 'scooter';
    case Taxi = 'taxi';
    case PrivateBus = 'privateBus';
}
