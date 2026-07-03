<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Static;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Geocoding;

/**
 * Runtime portion of the Geocoding PHPStan-level-8 narrowing contract per
 * *
 * The static portion lives in {@see /tests/Static/geocoding-narrowing-static.php} —
 * a PHPStan analysis target file that PHPStan must reject under level 8 when
 * a mis-paired (providerId, config) tuple is constructed. The valid pairings
 * in that same file must analyse clean. OSRM is excluded from the geocoding
 * union and is rejected statically (not in the union) and at
 * runtime via the constructor's explicit InvalidArgumentException arm.
 *
 * This PHPUnit test mirrors the runtime side: PHP's `assert` must reject
 * the same mis-paired tuples at run time when assertions are enabled.
 */
final class GeocodingNarrowingTest extends TestCase
{
    #[Test]
    public function googleProviderAcceptsGoogleConfig(): void
    {
        // Valid pairing should construct without runtime assert failure.
        $geocoding = new Geocoding(LocationProviderId::Google, new GoogleConfig(apiKey: 'k'));
        self::assertSame('google', $geocoding->getProviderId());
    }

    #[Test]
    public function mismatchedProviderConfigFailsAssertion(): void
    {
        // the per-arm `instanceof` is the single runtime gate; a mis-paired
        // in-union config throws `\LogicException` (was `\AssertionError` via the
        // now-removed redundant `assert`).
        $this->expectException(\LogicException::class);

        // @phpstan-ignore-next-line argument.type — deliberate mismatch to exercise runtime guard.
        new Geocoding(LocationProviderId::Google, new MapboxConfig(accessToken: 't'));
    }
}
