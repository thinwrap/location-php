<?php

declare(strict_types=1);

/**
 * PHPStan-level-8 narrowing target for (Isochrone facade).
 *
 * This file is analysed by PHPStan at level 8. It exercises the `Isochrone`
 * facade's union-typed `$config` parameter:
 *
 * Valid pairings (every isochrone-capable `LocationProviderId` case matched
 *     with its corresponding `*Config`) must analyse clean.
 * Google + OSRM are excluded (no first-class isochrone
 *     endpoint) — their `GoogleConfig`/`OsrmConfig` are NOT in the `Isochrone`
 *     union.
 * Invalid pairings would trigger `argument.type` errors and are NOT
 *     included in this file (PHPStan must analyse the file clean as a gate).
 *
 * To verify the negative side manually, temporarily uncomment the
 * `INTENTIONAL_PHPSTAN_FAILURE` block below and run
 * `vendor/bin/phpstan analyse tests/Static/isochrone-narrowing-static.php` —
 * PHPStan level 8 should report a `Parameter #2 $config` type mismatch for the
 * mismatched-config case AND for the Google/OSRM cases (their configs are not
 * in the union at all).
 */

namespace Thinwrap\Location\Tests\Static;

use Thinwrap\Location\Config\EsriConfig;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Config\TomTomConfig;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Isochrone;

// Valid pairings — must analyse clean at level 8.
$mapbox = new Isochrone(LocationProviderId::Mapbox, new MapboxConfig(accessToken: 't'));
$here = new Isochrone(LocationProviderId::Here, new HereConfig(apiKey: 'k'));
$esri = new Isochrone(LocationProviderId::Esri, new EsriConfig(apiKey: 'k'));
$tomtom = new Isochrone(LocationProviderId::TomTom, new TomTomConfig(apiKey: 'k'));

unset($mapbox, $here, $esri, $tomtom);

/*
 * INTENTIONAL_PHPSTAN_FAILURE — uncomment to verify negative narrowing.
 *
 * // Mismatched config — Mapbox paired with HereConfig.
 * $bad1 = new Isochrone(LocationProviderId::Mapbox, new HereConfig(apiKey: 'k'));
 *
 * // — Google is not in the Isochrone union at all.
 * $bad2 = new Isochrone(LocationProviderId::Google, new \Thinwrap\Location\Config\GoogleConfig(apiKey: 'k'));
 *
 * // — OSRM is not in the Isochrone union at all.
 * $bad3 = new Isochrone(LocationProviderId::Osrm, new \Thinwrap\Location\Config\OsrmConfig(baseUrl: 'https://x'));
 *
 * unset($bad1, $bad2, $bad3);
 */
