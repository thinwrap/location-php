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
use Thinwrap\Location\Connector\Esri\EsriMatrixConnector;
use Thinwrap\Location\Connector\Google\GoogleMatrixConnector;
use Thinwrap\Location\Connector\Here\HereMatrixConnector;
use Thinwrap\Location\Connector\Mapbox\MapboxMatrixConnector;
use Thinwrap\Location\Connector\Osrm\OsrmMatrixConnector;
use Thinwrap\Location\Connector\TomTom\TomTomMatrixConnector;
use Thinwrap\Location\Contract\MatrixConnectorInterface;
use Thinwrap\Location\DTO\Matrix\MatrixOptions;
use Thinwrap\Location\DTO\Matrix\MatrixResult;
use Thinwrap\Location\Enum\LocationProviderId;

/**
 * Unified Matrix facade — narrows per-provider config at PHPStan level 8.
 *
 * Mirrors {@see Routing}. The constructor accepts a
 * `LocationProviderId` enum case + a union of every provider config. PHPStan
 * level 8 statically rejects mismatched pairings at the call site via the union
 * parameter type; the per-arm `instanceof`→`\LogicException` is the single
 * runtime gate.
 *
 * facade-construction misuse is a PROGRAMMER error (`\LogicException`), NOT
 * part of the `ConnectorError` contract (parity with TS plain `Error`). The
 * redundant `assert` + `configMatchesProvider` helper were removed.
 */
final class Matrix implements MatrixConnectorInterface
{
    private readonly MatrixConnectorInterface $connector;

    public function __construct(
        public readonly LocationProviderId $providerId,
        GoogleConfig|MapboxConfig|HereConfig|EsriConfig|OsrmConfig|TomTomConfig $config,
        ?ClientInterface $httpClient = null,
    ) {
        // Per-arm `instanceof` keeps PHPStan-level-8 narrowing robust without
        // relying solely on conditional-return assertion narrowing piercing `match`.
        $this->connector = match ($providerId) {
            LocationProviderId::Google => $config instanceof GoogleConfig
                ? new GoogleMatrixConnector($config, $httpClient)
                : throw new \LogicException('Google provider requires GoogleConfig'),
            LocationProviderId::Mapbox => $config instanceof MapboxConfig
                ? new MapboxMatrixConnector($config, $httpClient)
                : throw new \LogicException('Mapbox provider requires MapboxConfig'),
            LocationProviderId::Here => $config instanceof HereConfig
                ? new HereMatrixConnector($config, $httpClient)
                : throw new \LogicException('Here provider requires HereConfig'),
            LocationProviderId::Esri => $config instanceof EsriConfig
                ? new EsriMatrixConnector($config, $httpClient)
                : throw new \LogicException('Esri provider requires EsriConfig'),
            LocationProviderId::Osrm => $config instanceof OsrmConfig
                ? new OsrmMatrixConnector($config, $httpClient)
                : throw new \LogicException('Osrm provider requires OsrmConfig'),
            LocationProviderId::TomTom => $config instanceof TomTomConfig
                ? new TomTomMatrixConnector($config, $httpClient)
                : throw new \LogicException('TomTom provider requires TomTomConfig'),
        };
    }

    public function getProviderId(): string
    {
        return $this->providerId->value;
    }

    public function matrix(MatrixOptions $options): MatrixResult
    {
        return $this->connector->matrix($options);
    }
}
