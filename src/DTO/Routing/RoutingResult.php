<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Routing;

final readonly class RoutingResult
{
    /**
     * @param list<RoutingLeg> $legs
     * @param list<int>|null $waypointOrder
     */
    public function __construct(
        public array $legs,
        public float $totalDistanceMeters,
        public float $totalDurationSeconds,
        public string $polyline,
        public ?array $waypointOrder = null,
        public mixed $raw = null,
    ) {}
}
