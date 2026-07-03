<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Geocoding;

final readonly class AutocompleteResult
{
    /**
     * @param list<AutocompletePrediction> $predictions
     */
    public function __construct(
        public array $predictions,
        public mixed $raw = null,
    ) {}
}
