<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Matrix;

use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Passthrough;
use Thinwrap\Location\Enum\TravelMode;

final readonly class MatrixOptions
{
    /** @var list<LatLng> */
    public array $origins;
    /** @var list<LatLng> */
    public array $destinations;
    public TravelMode $travelMode;

    /**
     * @param list<LatLng|array{0: float, 1: float}|array{lat: float, lng: float}> $origins
     * @param list<LatLng|array{0: float, 1: float}|array{lat: float, lng: float}> $destinations
     */
    public function __construct(
        array $origins,
        array $destinations,
        public bool $avoidTolls = false,
        TravelMode|string $travelMode = TravelMode::Driving,
        public ?\DateTimeInterface $departureTime = null,
        public ?Passthrough $passthrough = null,
    ) {
        $this->origins = LatLng::fromList($origins);
        $this->destinations = LatLng::fromList($destinations);
        $this->travelMode = is_string($travelMode) ? TravelMode::from($travelMode) : $travelMode;
    }
}
