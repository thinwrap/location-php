<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Isochrone;

final readonly class IsochroneContour
{
    /**
     * @param array<string, mixed> $geometry GeoJSON Geometry
     */
    public function __construct(
        public int|float $value,
        public array $geometry,
    ) {}
}
