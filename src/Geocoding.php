<?php

declare(strict_types=1);

namespace Thinwrap\Location;

use Psr\Http\Client\ClientInterface;
use Thinwrap\Location\Config\EsriConfig;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Config\TomTomConfig;
use Thinwrap\Location\Connector\Esri\EsriGeocodingConnector;
use Thinwrap\Location\Connector\Google\GoogleGeocodingConnector;
use Thinwrap\Location\Connector\Here\HereGeocodingConnector;
use Thinwrap\Location\Connector\Mapbox\MapboxGeocodingConnector;
use Thinwrap\Location\Connector\TomTom\TomTomGeocodingConnector;
use Thinwrap\Location\Contract\GeocodingConnectorInterface;
use Thinwrap\Location\DTO\Geocoding\AutocompleteOptions;
use Thinwrap\Location\DTO\Geocoding\AutocompleteResult;
use Thinwrap\Location\DTO\Geocoding\GeocodeOptions;
use Thinwrap\Location\DTO\Geocoding\GeocodeResult;
use Thinwrap\Location\DTO\Geocoding\ReverseGeocodeOptions;
use Thinwrap\Location\DTO\Geocoding\ReverseGeocodeResult;
use Thinwrap\Location\Enum\LocationProviderId;

/**
 * Unified Geocoding facade — narrows per-provider config at PHPStan level 8.
 *
 * Mirrors {@see Routing} and {@see Matrix}. The
 * constructor accepts a `LocationProviderId` enum case + a union of every
 * geocoding-capable provider config (excludes OSRM — no provider
 * geocoding endpoint). PHPStan level 8 statically rejects mismatched pairings at
 * the call site via the union parameter type; the per-arm `instanceof`→
 * `\LogicException` is the single runtime gate.
 *
 * facade-construction misuse is a PROGRAMMER error (`\LogicException`), NOT
 * part of the `ConnectorError` contract (parity with TS plain `Error`). The
 * redundant `assert` + `configMatchesProvider` helper were removed, and the
 * OSRM arm's `\InvalidArgumentException` was unified to `\LogicException`.
 *
 * Surfaces three methods of the umbrella story:
 * `geocode` — forward geocoding (address -> candidates[]).
 * `reverseGeocode` — reverse geocoding (LatLng -> candidates[],
 * honesty correction).
 * `autocomplete` — query-as-you-type predictions.
 */
final class Geocoding implements GeocodingConnectorInterface
{
    private readonly GeocodingConnectorInterface $connector;

    public function __construct(
        public readonly LocationProviderId $providerId,
        GoogleConfig|MapboxConfig|HereConfig|EsriConfig|TomTomConfig $config,
        ?ClientInterface $httpClient = null,
    ) {
        // Per-arm `instanceof` keeps PHPStan-level-8 narrowing robust without
        // relying solely on conditional-return assertion narrowing piercing `match`.
        $this->connector = match ($providerId) {
            LocationProviderId::Google => $config instanceof GoogleConfig
                ? new GoogleGeocodingConnector($config, $httpClient)
                : throw new \LogicException('Google provider requires GoogleConfig'),
            LocationProviderId::Mapbox => $config instanceof MapboxConfig
                ? new MapboxGeocodingConnector($config, $httpClient)
                : throw new \LogicException('Mapbox provider requires MapboxConfig'),
            LocationProviderId::Here => $config instanceof HereConfig
                ? new HereGeocodingConnector($config, $httpClient)
                : throw new \LogicException('Here provider requires HereConfig'),
            LocationProviderId::Esri => $config instanceof EsriConfig
                ? new EsriGeocodingConnector($config, $httpClient)
                : throw new \LogicException('Esri provider requires EsriConfig'),
            LocationProviderId::TomTom => $config instanceof TomTomConfig
                ? new TomTomGeocodingConnector($config, $httpClient)
                : throw new \LogicException('TomTom provider requires TomTomConfig'),
            // OSRM has no geocoding endpoint. Passing a config OUTSIDE
            // the union is rejected by a native `\TypeError` at the parameter
            // boundary; an in-union config with OSRM is programmer misuse →
            // `\LogicException` (one construction-error type).
            LocationProviderId::Osrm => throw new \LogicException(
                'OSRM does not support geocoding',
            ),
        };
    }

    public function getProviderId(): string
    {
        return $this->providerId->value;
    }

    public function geocode(GeocodeOptions $options): GeocodeResult
    {
        return $this->connector->geocode($options);
    }

    public function reverseGeocode(ReverseGeocodeOptions $options): ReverseGeocodeResult
    {
        return $this->connector->reverseGeocode($options);
    }

    public function autocomplete(AutocompleteOptions $options): AutocompleteResult
    {
        return $this->connector->autocomplete($options);
    }
}
