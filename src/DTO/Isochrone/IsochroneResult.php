<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Isochrone;

/**
 * Unified Isochrone result.
 *
 * `_meta.requestCount` exposes the underlying number of HTTP calls a connector
 * issued — relevant for providers that don't accept multiple contour values per
 * request (TomTom fans out one call per value). Per the PINNED cross-language
 * contract, `_meta` is present **iff more than one** underlying HTTP call
 * was made (N > 1); single-call paths OMIT it entirely. The field is spelled
 * with a leading underscore to match the location-ts sibling.
 */
final readonly class IsochroneResult
{
    /**
     * @param list<IsochroneContour> $contours
     * @param array{requestCount: int}|null $_meta Present iff N > 1 underlying HTTP calls.
     */
    public function __construct(
        public array $contours,
        public mixed $raw = null,
        public ?array $_meta = null,
    ) {}
}
