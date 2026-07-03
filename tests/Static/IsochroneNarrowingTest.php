<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Static;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Isochrone;

/**
 * Runtime portion of the Isochrone PHPStan-level-8 narrowing contract
 * *
 * The static portion lives in {@see /tests/Static/isochrone-narrowing-static.php} —
 * a PHPStan analysis target file that PHPStan must reject under level 8 when a
 * mis-paired (providerId, config) tuple is constructed. The valid pairings in
 * that same file must analyse clean. Google + OSRM are excluded from the
 * isochrone union: their configs are not in the union, so PHP
 * rejects them with a native \TypeError at the parameter boundary (exercised by
 * {@see \Thinwrap\Location\Tests\Unit\Facade\IsochroneTest::itThrowsForUnsupportedProvider}).
 *
 * This PHPUnit test mirrors the runtime side: PHP's `assert` must reject the
 * same mis-paired tuples at run time when assertions are enabled.
 */
final class IsochroneNarrowingTest extends TestCase
{
    #[Test]
    public function mapboxProviderAcceptsMapboxConfig(): void
    {
        // Valid pairing should construct without runtime assert failure.
        $isochrone = new Isochrone(LocationProviderId::Mapbox, new MapboxConfig(accessToken: 't'));
        self::assertSame('mapbox', $isochrone->getProviderId());
    }

    #[Test]
    public function mismatchedProviderConfigFailsAssertion(): void
    {
        // the per-arm `instanceof` is the single runtime gate; a mis-paired
        // in-union config throws `\LogicException` (was `\AssertionError` via the
        // now-removed redundant `assert`).
        $this->expectException(\LogicException::class);

        // @phpstan-ignore-next-line argument.type — deliberate mismatch to exercise runtime guard.
        new Isochrone(LocationProviderId::Mapbox, new HereConfig(apiKey: 'k'));
    }
}
