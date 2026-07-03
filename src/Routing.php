<?php

declare(strict_types=1);

namespace Thinwrap\Location;

use Psr\Http\Client\ClientInterface;
use Thinwrap\Location\Config\EsriConfig;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Config\OsrmConfig;
use Thinwrap\Location\Config\TomTomConfig;
use Thinwrap\Location\Connector\Esri\EsriRoutingConnector;
use Thinwrap\Location\Connector\Google\GoogleRoutingConnector;
use Thinwrap\Location\Connector\Here\HereRoutingConnector;
use Thinwrap\Location\Connector\Mapbox\MapboxRoutingConnector;
use Thinwrap\Location\Connector\Osrm\OsrmRoutingConnector;
use Thinwrap\Location\Connector\TomTom\TomTomRoutingConnector;
use Thinwrap\Location\Contract\RoutingConnectorInterface;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\DTO\Routing\RoutingResult;
use Thinwrap\Location\Enum\LocationProviderId;

/**
 * Unified Routing facade — narrows per-provider config at PHPStan level 8.
 *
 * The constructor accepts a `LocationProviderId` enum case + a union of every
 * provider config. PHPStan level 8 statically rejects mismatched pairings at the
 * call site via the union parameter type; the per-arm `instanceof`→
 * `\LogicException` is the single runtime gate.
 *
 * (construction-error contract): facade-construction misuse is a PROGRAMMER
 * error (`\LogicException`), NOT part of the `ConnectorError` contract — parity
 * with the TS sibling's plain `Error`. The previously redundant `assert` +
 * its `configMatchesProvider` helper were removed (the per-arm `instanceof` is
 * the sole gate).
 */
final class Routing implements RoutingConnectorInterface
{
    private readonly RoutingConnectorInterface $connector;

    public function __construct(
        public readonly LocationProviderId $providerId,
        GoogleConfig|MapboxConfig|HereConfig|EsriConfig|OsrmConfig|TomTomConfig $config,
        ?ClientInterface $httpClient = null,
    ) {
        // Per-arm `instanceof` keeps PHPStan-level-8 narrowing robust without
        // relying solely on conditional-return assertion narrowing piercing `match`.
        $this->connector = match ($providerId) {
            LocationProviderId::Google => $config instanceof GoogleConfig
                ? new GoogleRoutingConnector($config, $httpClient)
                : throw new \LogicException('Google provider requires GoogleConfig'),
            LocationProviderId::Mapbox => $config instanceof MapboxConfig
                ? new MapboxRoutingConnector($config, $httpClient)
                : throw new \LogicException('Mapbox provider requires MapboxConfig'),
            LocationProviderId::Here => $config instanceof HereConfig
                ? new HereRoutingConnector($config, $httpClient)
                : throw new \LogicException('Here provider requires HereConfig'),
            LocationProviderId::Esri => $config instanceof EsriConfig
                ? new EsriRoutingConnector($config, $httpClient)
                : throw new \LogicException('Esri provider requires EsriConfig'),
            LocationProviderId::Osrm => $config instanceof OsrmConfig
                ? new OsrmRoutingConnector($config, $httpClient)
                : throw new \LogicException('Osrm provider requires OsrmConfig'),
            LocationProviderId::TomTom => $config instanceof TomTomConfig
                ? new TomTomRoutingConnector($config, $httpClient)
                : throw new \LogicException('TomTom provider requires TomTomConfig'),
        };
    }

    public function getProviderId(): string
    {
        return $this->providerId->value;
    }

    public function route(RoutingOptions $options): RoutingResult
    {
        return $this->connector->route($options);
    }
}
