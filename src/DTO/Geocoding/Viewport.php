<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Geocoding;

use Thinwrap\Location\DTO\LatLng;

/**
 * Viewport bounding box — promoted to a base output type because
 * all 5 supported geocoding providers natively return a viewport for each
 * geocode candidate. Carried on {@see GeocodeCandidate::$viewport}.
 */
final readonly class Viewport
{
    public function __construct(
        public LatLng $southwest,
        public LatLng $northeast,
    ) {}
}
