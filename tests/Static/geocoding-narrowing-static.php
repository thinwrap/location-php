<?php

declare(strict_types=1);

/**
 * PHPStan-level-8 narrowing target for.
 *
 * This file is analysed by PHPStan at level 8. It exercises the `Geocoding`
 * facade's union-typed `$config` parameter:
 *
 * Valid pairings (every geocoding-capable `LocationProviderId` case matched
 *     with its corresponding `*Config`) must analyse clean.
 * OSRM is excluded (no provider geocoding endpoint) — its
 *     `OsrmConfig` is NOT in the `Geocoding` union.
 * Invalid pairings would trigger `argument.type` errors and are NOT
 *     included in this file (PHPStan must analyse the file clean as a gate).
 *
 * To verify the negative side manually, temporarily uncomment the
 * `INTENTIONAL_PHPSTAN_FAILURE` block below and run
 * `vendor/bin/phpstan analyse tests/Static/geocoding-narrowing-static.php` —
 * PHPStan level 8 should report a `Parameter #2 $config` type mismatch for
 * the mismatched-config case AND for the OSRM case (OsrmConfig is not in the
 * union at all).
 */

namespace Thinwrap\Location\Tests\Static;

use Thinwrap\Location\Config\EsriConfig;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Config\TomTomConfig;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Geocoding;

// Valid pairings — must analyse clean at level 8.
$google = new Geocoding(LocationProviderId::Google, new GoogleConfig(apiKey: 'k'));
$mapbox = new Geocoding(LocationProviderId::Mapbox, new MapboxConfig(accessToken: 't'));
$here = new Geocoding(LocationProviderId::Here, new HereConfig(apiKey: 'k'));
$esri = new Geocoding(LocationProviderId::Esri, new EsriConfig(apiKey: 'k'));
$tomtom = new Geocoding(LocationProviderId::TomTom, new TomTomConfig(apiKey: 'k'));

unset($google, $mapbox, $here, $esri, $tomtom);

/*
 * INTENTIONAL_PHPSTAN_FAILURE — uncomment to verify negative narrowing.
 *
 * // Mismatched config — Google paired with MapboxConfig.
 * $bad1 = new Geocoding(LocationProviderId::Google, new MapboxConfig(accessToken: 't'));
 *
 * // — OSRM is not in the Geocoding union at all.
 * $bad2 = new Geocoding(LocationProviderId::Osrm, new \Thinwrap\Location\Config\OsrmConfig(baseUrl: 'https://x'));
 *
 * unset($bad1, $bad2);
 */
