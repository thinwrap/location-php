<?php

declare(strict_types=1);

namespace Thinwrap\Location;

use Psr\Http\Client\ClientInterface;
use Thinwrap\Location\Config\EsriConfig;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Config\TomTomConfig;
use Thinwrap\Location\Connector\Esri\EsriIsochroneConnector;
use Thinwrap\Location\Connector\Here\HereIsochroneConnector;
use Thinwrap\Location\Connector\Mapbox\MapboxIsochroneConnector;
use Thinwrap\Location\Connector\TomTom\TomTomIsochroneConnector;
use Thinwrap\Location\Contract\IsochroneConnectorInterface;
use Thinwrap\Location\DTO\Isochrone\IsochroneOptions;
use Thinwrap\Location\DTO\Isochrone\IsochroneResult;
use Thinwrap\Location\Enum\LocationProviderId;

/**
 * Unified Isochrone facade — narrows per-provider config at PHPStan level 8.
 *
 * Mirrors {@see Routing}, {@see Matrix} and
 * {@see Geocoding}. The constructor accepts a `LocationProviderId`
 * enum case + a union of every isochrone-capable provider config.
 *
 * Baseline coverage discipline (≥90% rule): Google + OSRM are
 * EXCLUDED from the union because neither offers a first-class isochrone
 * endpoint. The union is narrowed to `Mapbox | Here | Esri | TomTom`.
 * PHPStan level 8 statically rejects mismatched pairings; the per-arm
 * `instanceof`→`\LogicException` is the single runtime gate.
 *
 * (construction-error contract): facade-construction misuse (a mis-paired
 * `(providerId, config)`) is a PROGRAMMER error, NOT part of the `ConnectorError`
 * contract — parity with the TS sibling, which throws a plain `Error` here. A
 * config outside the narrowed union is rejected by a native `\TypeError` at the
 * parameter boundary before the constructor body runs; the surviving
 * `instanceof` arms throw `\LogicException`. (The previously hand-written
 * `\InvalidArgumentException` arms for Google/OSRM were unreachable dead code —
 * removed.)
 *
 * Implements {@see IsochroneConnectorInterface}: PHP is type-sound here because
 * the base {@see IsochroneOptions} does NOT per-provider-widen `travelMode`
 * (cycling is rejected at the DTO), so the contravariance reason that makes
 * the TS facade deliberately NOT implement the interface does not apply. This
 * divergence-from-TS is accepted + recorded (mirrors the ts parity-audit
 * whitelist).
 */
final class Isochrone implements IsochroneConnectorInterface
{
    private readonly IsochroneConnectorInterface $connector;

    public function __construct(
        public readonly LocationProviderId $providerId,
        MapboxConfig|HereConfig|EsriConfig|TomTomConfig $config,
        ?ClientInterface $httpClient = null,
    ) {
        // Per-arm `instanceof` keeps PHPStan-level-8 narrowing robust without
        // relying solely on conditional-return assertion narrowing piercing `match`.
        // Google/OSRM are not in the config union; passing them with an
        // in-union config hits an `\UnhandledMatchError` (a programmer error,
        //) — they have no isochrone endpoint (baseline coverage).
        $this->connector = match ($providerId) {
            LocationProviderId::Mapbox => $config instanceof MapboxConfig
                ? new MapboxIsochroneConnector($config, $httpClient)
                : throw new \LogicException('Mapbox provider requires MapboxConfig'),
            LocationProviderId::Here => $config instanceof HereConfig
                ? new HereIsochroneConnector($config, $httpClient)
                : throw new \LogicException('Here provider requires HereConfig'),
            LocationProviderId::Esri => $config instanceof EsriConfig
                ? new EsriIsochroneConnector($config, $httpClient)
                : throw new \LogicException('Esri provider requires EsriConfig'),
            LocationProviderId::TomTom => $config instanceof TomTomConfig
                ? new TomTomIsochroneConnector($config, $httpClient)
                : throw new \LogicException('TomTom provider requires TomTomConfig'),
            // Google/OSRM have no isochrone endpoint (baseline coverage).
            // Passing a config OUTSIDE the union is rejected by a native
            // `\TypeError` at the parameter boundary; passing an in-union config
            // with these ids is programmer misuse → `\LogicException` (: one
            // construction-error type; NOT a `ConnectorError`).
            LocationProviderId::Google, LocationProviderId::Osrm => throw new \LogicException(
                "{$providerId->value} does not support isochrone",
            ),
        };
    }

    public function getProviderId(): string
    {
        return $this->providerId->value;
    }

    public function isochrone(IsochroneOptions $options): IsochroneResult
    {
        return $this->connector->isochrone($options);
    }
}
