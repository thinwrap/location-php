<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Geocoding;

/**
 * Reverse-geocode result.
 *
 * Per honesty correction: this result mirrors the forward-geocode shape
 * (`$candidates: list<GeocodeCandidate>`) rather than carrying a single
 * `formattedAddress`. 4 of 5 supported providers return a ranked list of
 * features; ESRI (1/5) returns a single feature wrapped to a one-element
 * array inside its connector.
 */
final readonly class ReverseGeocodeResult
{
    /**
     * @param list<GeocodeCandidate> $candidates
     */
    public function __construct(
        public array $candidates,
        public mixed $raw = null,
    ) {}
}
