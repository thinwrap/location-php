<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO\Geocoding;

use Thinwrap\Location\DTO\Passthrough;

/**
 * Forward-geocoding options.
 *
 * Per honesty correction: the optional country filter is named
 * `countryFilter` (an array of ISO 3166-1 alpha-2 codes acting as a hard
 * filter), NOT the legacy `region` single-string field — connectors map it
 * to each provider's native country-restriction parameter.
 */
final readonly class GeocodeOptions
{
    /**
     * @param list<string>|null $countryFilter ISO 3166-1 alpha-2 codes; hard filter.
     */
    public function __construct(
        public string $address,
        public ?string $language = null,
        public ?array $countryFilter = null,
        public ?Passthrough $passthrough = null,
    ) {}
}
