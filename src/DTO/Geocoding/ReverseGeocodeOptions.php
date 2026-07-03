<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Geocoding;

use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough;

final readonly class ReverseGeocodeOptions
{
    public LatLng $location;

    /**
     * @param LatLng|array{0: float, 1: float}|array{lat: float, lng: float} $location
     */
    public function __construct(
        LatLng|array $location,
        public ?string $language = null,
        public ?Passthrough $passthrough = null,
    ) {
        $this->location = LatLng::from($location);
    }
}
