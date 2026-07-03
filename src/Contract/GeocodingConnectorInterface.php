<?php

declare(strict_types=1);

namespace Thinwrap\Location\Contract;

use Thinwrap\Location\DTO\Geocoding\AutocompleteOptions;
use Thinwrap\Location\DTO\Geocoding\AutocompleteResult;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\DTO\Geocoding\GeocodeResult;
use Thinwrap\Location\DTO\Geocoding\ReverseGeocodeOptions;
use Thinwrap\Location\DTO\Geocoding\ReverseGeocodeResult;

interface GeocodingConnectorInterface
{
    public function getProviderId(): string;

    public function geocode(GeocodeOptions $options): GeocodeResult;

    public function reverseGeocode(ReverseGeocodeOptions $options): ReverseGeocodeResult;

    public function autocomplete(AutocompleteOptions $options): AutocompleteResult;
}
