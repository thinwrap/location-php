<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Util\Polyline;

/**
 * TS↔PHP byte-for-byte parity fixture.
 *
 * Canonical vectors sourced from locked test cases. Until the
 * `thinwrap/test-fixtures` repo is published, vectors live in
 * `tests/Fixture/polyline/parity-vectors.json` — a verbatim copy of the TS-side
 * expectations. Any TS-side drift in those vectors blocks v1.0 on both languages.
 */
final class PolylineParityTest extends TestCase
{
    /**
     * @return array<string, array{lat: float, lng: float}|list<mixed>|string|array{paths: list<list<list<float>>>, spatialReference: array{wkid: int}}>
     */
    private static function fixtures(): array
    {
        $path = __DIR__ . '/../../Fixture/polyline/parity-vectors.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read parity fixtures at {$path}");
        }
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    #[Test]
    public function encodePolylineMatchesTsFixturesByteForByte(): void
    {
        /** @var list<array{name: string, input: list<array{lat: float, lng: float}>, expected: string}> $cases */
        $cases = self::fixtures()['encodePolyline'];

        foreach ($cases as $case) {
            $coords = array_map(
                static fn(array $p): LatLng => new LatLng((float) $p['lat'], (float) $p['lng']),
                $case['input'],
            );

            $actual = Polyline::encodePolyline($coords);

            $this->assertSame(
                $case['expected'],
                $actual,
                "encodePolyline parity failed for fixture: {$case['name']}",
            );
        }
    }

    #[Test]
    public function decodePolylineMatchesTsFixturesWithinTolerance(): void
    {
        /** @var list<array{name: string, input: string, expected: list<array{lat: float, lng: float}>}> $cases */
        $cases = self::fixtures()['decodePolyline'];

        foreach ($cases as $case) {
            $actual = Polyline::decodePolyline($case['input']);

            $this->assertCount(
                count($case['expected']),
                $actual,
                "decodePolyline count mismatch for fixture: {$case['name']}",
            );

            foreach ($case['expected'] as $i => $expectedPoint) {
                $this->assertEqualsWithDelta(
                    (float) $expectedPoint['lat'],
                    $actual[$i]->lat,
                    5e-5,
                    "decodePolyline lat parity for '{$case['name']}' at index {$i}",
                );
                $this->assertEqualsWithDelta(
                    (float) $expectedPoint['lng'],
                    $actual[$i]->lng,
                    5e-5,
                    "decodePolyline lng parity for '{$case['name']}' at index {$i}",
                );
            }
        }
    }

    #[Test]
    public function decodeFlexPolylineMatchesTsFixturesWithinTolerance(): void
    {
        /** @var list<array{name: string, input: string, expected: list<array{lat: float, lng: float}>}> $cases */
        $cases = self::fixtures()['decodeFlexPolyline'];

        foreach ($cases as $case) {
            $actual = Polyline::decodeFlexPolyline($case['input']);

            $this->assertCount(
                count($case['expected']),
                $actual,
                "decodeFlexPolyline count mismatch for fixture: {$case['name']}",
            );

            foreach ($case['expected'] as $i => $expectedPoint) {
                $this->assertEqualsWithDelta(
                    (float) $expectedPoint['lat'],
                    $actual[$i]->lat,
                    5e-5,
                    "decodeFlexPolyline lat parity for '{$case['name']}' at index {$i}",
                );
                $this->assertEqualsWithDelta(
                    (float) $expectedPoint['lng'],
                    $actual[$i]->lng,
                    5e-5,
                    "decodeFlexPolyline lng parity for '{$case['name']}' at index {$i}",
                );
            }
        }
    }

    #[Test]
    public function encodeEsriPathsMatchesTsFixturesByteForByte(): void
    {
        /** @var list<array{name: string, input: list<list<array{lat: float, lng: float}>>, expected: array{paths: list<list<list<float>>>, spatialReference: array{wkid: int}}}> $cases */
        $cases = self::fixtures()['encodeEsriPaths'];

        foreach ($cases as $case) {
            $input = array_map(
                static fn(array $path): array => array_map(
                    static fn(array $p): LatLng => new LatLng((float) $p['lat'], (float) $p['lng']),
                    $path,
                ),
                $case['input'],
            );

            $actual = Polyline::encodeEsriPaths($input);

            // JSON-serialize both sides for byte-equal comparison (handles int/float ambiguity).
            $this->assertSame(
                json_encode($case['expected']),
                json_encode($actual),
                "encodeEsriPaths parity failed for fixture: {$case['name']}",
            );
        }
    }

    /**
     * P1 PHP-half performance guard: a 1000-point round-trip stays fast.
     *
     * Correctness (1000 points survive the round-trip) is always asserted.
     * The wall-clock bound only runs when NO coverage driver is loaded —
     * coverage instrumentation (xdebug in CI) inflates timings several-fold,
     * which made a tight millisecond gate flaky. With instrumentation off the
     * bound still catches algorithmic regressions (e.g. an O(n^2) blowup).
     */
    #[Test]
    public function thousandPointRoundTripStaysFast(): void
    {
        $coords = [];
        for ($i = 0; $i < 1000; $i++) {
            $coords[] = new LatLng($i * 0.001 + 40, $i * 0.002 - 80);
        }

        // Warmup so the opcache JIT / interpreter cache the call paths.
        Polyline::encodePolyline($coords);
        Polyline::decodePolyline(Polyline::encodePolyline($coords));

        $start = microtime(true);
        $encoded = Polyline::encodePolyline($coords);
        $decoded = Polyline::decodePolyline($encoded);
        $elapsedMs = (microtime(true) - $start) * 1000.0;

        $this->assertCount(1000, $decoded);

        if (!extension_loaded('xdebug') && !extension_loaded('pcov')) {
            $this->assertLessThan(
                25.0,
                $elapsedMs,
                sprintf('1000-point round-trip took %.3f ms (guard: <25 ms)', $elapsedMs),
            );
        }
    }
}
