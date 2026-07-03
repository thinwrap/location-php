<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Geocoding;

use Thinwrap\Location\DTO\LatLng;

/**
 * Normalized geocode candidate.
 *
 * Per honesty correction: `$viewport` is promoted to a base field
 * because all 5 supported geocoding providers natively return a bounding
 * viewport for each result. Connectors that don't surface one leave it null.
 */
final readonly class GeocodeCandidate
{
    public function __construct(
        public string $formattedAddress,
        public LatLng $location,
        public ?string $placeId = null,
        public ?Viewport $viewport = null,
    ) {}
}
