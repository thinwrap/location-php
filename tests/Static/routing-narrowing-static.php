<?php

declare(strict_types=1);

/**
 * PHPStan-level-8 narrowing target for.
 *
 * This file is analysed by PHPStan at level 8. It exercises the `Routing`
 * facade's union-typed `$config` parameter:
 *
 * Valid pairings (every `LocationProviderId` case matched with its corresponding
 *     `*Config`) must analyse clean.
 * Invalid pairings would trigger `argument.type` errors and are NOT included
 *     in this file (PHPStan must analyse the file clean as a gate).
 *
 * To verify the negative side manually, temporarily uncomment the
 * `INTENTIONAL_PHPSTAN_FAILURE` block below and run
 * `vendor/bin/phpstan analyse tests/Static/routing-narrowing-static.php` —
 * PHPStan level 8 should report a `Parameter #2 $config` type mismatch.
 */

namespace Thinwrap\Location\Tests\Static;

use Thinwrap\Location\Config\EsriConfig;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Config\OsrmConfig;
use Thinwrap\Location\Config\TomTomConfig;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Routing;

// Valid pairings — must analyse clean at level 8.
$google = new Routing(LocationProviderId::Google, new GoogleConfig(apiKey: 'k'));
$mapbox = new Routing(LocationProviderId::Mapbox, new MapboxConfig(accessToken: 't'));
$here = new Routing(LocationProviderId::Here, new HereConfig(apiKey: 'k'));
$esri = new Routing(LocationProviderId::Esri, new EsriConfig(apiKey: 'k'));
$osrm = new Routing(LocationProviderId::Osrm, new OsrmConfig(baseUrl: 'https://router.project-osrm.org'));
$tomtom = new Routing(LocationProviderId::TomTom, new TomTomConfig(apiKey: 'k'));

unset($google, $mapbox, $here, $esri, $osrm, $tomtom);

/*
 * INTENTIONAL_PHPSTAN_FAILURE — uncomment to verify negative narrowing.
 *
 * $bad = new Routing(LocationProviderId::Google, new MapboxConfig(accessToken: 't'));
 * unset($bad);
 */
