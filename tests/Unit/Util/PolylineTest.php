<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Util\Polyline;

final class PolylineTest extends TestCase
{
    #[Test]
    public function encodePolylineProducesGoogleCanonicalString(): void
    {
        $coords = [
            new LatLng(38.5, -120.2),
            new LatLng(40.7, -120.95),
            new LatLng(43.252, -126.453),
        ];

        $encoded = Polyline::encodePolyline($coords);

        // Byte-for-byte parity with reference vector.
        $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', $encoded);
    }

    #[Test]
    public function decodePolylineRecoversGoogleCanonicalCoordinates(): void
    {
        $coords = Polyline::decodePolyline('_p~iF~ps|U_ulLnnqC_mqNvxq`@');

        $this->assertCount(3, $coords);

        $this->assertEqualsWithDelta(38.5, $coords[0]->lat, 5e-5);
        $this->assertEqualsWithDelta(-120.2, $coords[0]->lng, 5e-5);

        $this->assertEqualsWithDelta(40.7, $coords[1]->lat, 5e-5);
        $this->assertEqualsWithDelta(-120.95, $coords[1]->lng, 5e-5);

        $this->assertEqualsWithDelta(43.252, $coords[2]->lat, 5e-5);
        $this->assertEqualsWithDelta(-126.453, $coords[2]->lng, 5e-5);
    }

    #[Test]
    public function encodeDecodeRoundTripsWithinPrecision5Tolerance(): void
    {
        $original = [
            new LatLng(34.0522, -118.2437),
            new LatLng(36.1699, -115.1398),
            new LatLng(39.7392, -104.9903),
        ];

        $encoded = Polyline::encodePolyline($original);
        $decoded = Polyline::decodePolyline($encoded);

        $this->assertCount(count($original), $decoded);

        foreach ($original as $i => $coord) {
            $this->assertEqualsWithDelta($coord->lat, $decoded[$i]->lat, 5e-5);
            $this->assertEqualsWithDelta($coord->lng, $decoded[$i]->lng, 5e-5);
        }
    }

    #[Test]
    public function encodePolylineHandlesEmptyArray(): void
    {
        $this->assertSame('', Polyline::encodePolyline([]));
    }

    #[Test]
    public function decodePolylineHandlesEmptyString(): void
    {
        $this->assertSame([], Polyline::decodePolyline(''));
    }

    #[Test]
    public function encodePolylineHandlesAntipodes(): void
    {
        $coords = [
            new LatLng(90.0, 180.0),
            new LatLng(-90.0, -180.0),
        ];

        $encoded = Polyline::encodePolyline($coords);
        $decoded = Polyline::decodePolyline($encoded);

        $this->assertCount(2, $decoded);
        $this->assertEqualsWithDelta(90.0, $decoded[0]->lat, 5e-5);
        $this->assertEqualsWithDelta(180.0, $decoded[0]->lng, 5e-5);
        $this->assertEqualsWithDelta(-90.0, $decoded[1]->lat, 5e-5);
        $this->assertEqualsWithDelta(-180.0, $decoded[1]->lng, 5e-5);
    }

    #[Test]
    public function encodePolylineHandlesRepeatedIdenticalPoints(): void
    {
        $coords = [
            new LatLng(1.0, 1.0),
            new LatLng(1.0, 1.0),
            new LatLng(1.0, 1.0),
        ];

        $encoded = Polyline::encodePolyline($coords);
        $decoded = Polyline::decodePolyline($encoded);

        $this->assertCount(3, $decoded);
        foreach ($decoded as $point) {
            $this->assertEqualsWithDelta(1.0, $point->lat, 5e-5);
            $this->assertEqualsWithDelta(1.0, $point->lng, 5e-5);
        }
    }

    #[Test]
    public function decodeFlexPolylineHandlesHereCanonicalVector(): void
    {
        // Byte-for-byte parity with the HERE flexible-polyline canonical README
        // vector. This exact 24-char input decodes to 4 coordinates per HERE's
        // reference implementation (the string ends after the 4th point); the
        // prior 5-point expectation carried a spurious extra coordinate.
        $coords = Polyline::decodeFlexPolyline('BFoz5xJ67i1B1B7PzIhaxL7Y');

        $this->assertCount(4, $coords);

        $expected = [
            [50.10228, 8.69821],
            [50.10201, 8.69567],
            [50.10063, 8.69150],
            [50.09878, 8.68752],
        ];

        foreach ($expected as $i => [$lat, $lng]) {
            $this->assertEqualsWithDelta($lat, $coords[$i]->lat, 5e-5);
            $this->assertEqualsWithDelta($lng, $coords[$i]->lng, 5e-5);
        }
    }

    #[Test]
    public function encodeEsriPathsReturnsEsriGeometryShape(): void
    {
        $paths = [[
            new LatLng(40.0, -74.0),
            new LatLng(41.0, -73.0),
        ]];

        $result = Polyline::encodeEsriPaths($paths);

        $this->assertSame(
            [
                'paths' => [[[-74.0, 40.0], [-73.0, 41.0]]],
                'spatialReference' => ['wkid' => 4326],
            ],
            $result,
        );
    }

    #[Test]
    public function encodeEsriPathsHandlesMultiplePaths(): void
    {
        $paths = [
            [new LatLng(38.5, -120.2), new LatLng(40.7, -120.95)],
            [new LatLng(43.252, -126.453)],
        ];

        $result = Polyline::encodeEsriPaths($paths);

        $this->assertCount(2, $result['paths']);
        $this->assertSame([[-120.2, 38.5], [-120.95, 40.7]], $result['paths'][0]);
        $this->assertSame([[-126.453, 43.252]], $result['paths'][1]);
        $this->assertSame(['wkid' => 4326], $result['spatialReference']);
    }

    #[Test]
    public function encodeEsriPathsHandlesEmptyInput(): void
    {
        $result = Polyline::encodeEsriPaths([]);
        $this->assertSame(['paths' => [], 'spatialReference' => ['wkid' => 4326]], $result);
    }

    #[Test]
    public function encodeEsriPathsHandlesEmptyInnerPath(): void
    {
        $result = Polyline::encodeEsriPaths([[]]);
        $this->assertSame(['paths' => [[]], 'spatialReference' => ['wkid' => 4326]], $result);
    }
}
