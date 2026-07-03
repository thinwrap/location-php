<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Geocoding;

use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough;

final readonly class AutocompleteOptions
{
    public ?LatLng $location;

    /**
     * @param LatLng|array{0: float, 1: float}|array{lat: float, lng: float}|null $location
     */
    public function __construct(
        public string $input,
        LatLng|array|null $location = null,
        public ?float $radius = null,
        public ?string $language = null,
        public ?Passthrough $passthrough = null,
    ) {
        $this->location = $location !== null ? LatLng::from($location) : null;
    }
}
