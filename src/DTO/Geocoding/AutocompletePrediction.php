<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Geocoding;

final readonly class AutocompletePrediction
{
    public function __construct(
        public string $description,
        public ?string $placeId = null,
    ) {}
}
