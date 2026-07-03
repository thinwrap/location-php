<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Routing;

final readonly class RoutingLeg
{
    public function __construct(
        public float $distanceMeters,
        public float $durationSeconds,
    ) {}
}
