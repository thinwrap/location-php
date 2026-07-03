<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Geocoding;

final readonly class GeocodeResult
{
    /**
     * @param list<GeocodeCandidate> $candidates
     */
    public function __construct(
        public array $candidates,
        public mixed $raw = null,
    ) {}
}
