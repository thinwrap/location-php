<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Util\Coordinate;

final class CoordinateTest extends TestCase
{
    #[Test]
    public function joinLngLatWithMultipleCoordinates(): void
    {
        $coords = [
            new LatLng(38.5, -120.2),
            new LatLng(40.7, -120.95),
            new LatLng(43.252, -126.453),
        ];

        $result = Coordinate::joinLngLat($coords, ';');

        $this->assertSame('-120.2,38.5;-120.95,40.7;-126.453,43.252', $result);
    }

    #[Test]
    public function joinLatLngWithMultipleCoordinates(): void
    {
        $coords = [
            new LatLng(38.5, -120.2),
            new LatLng(40.7, -120.95),
            new LatLng(43.252, -126.453),
        ];

        $result = Coordinate::joinLatLng($coords, '|');

        $this->assertSame('38.5,-120.2|40.7,-120.95|43.252,-126.453', $result);
    }

    #[Test]
    public function joinLngLatWithSingleCoordinate(): void
    {
        $coords = [new LatLng(51.5074, -0.1278)];

        $result = Coordinate::joinLngLat($coords, ';');

        $this->assertSame('-0.1278,51.5074', $result);
    }

    #[Test]
    public function joinLatLngWithSingleCoordinate(): void
    {
        $coords = [new LatLng(51.5074, -0.1278)];

        $result = Coordinate::joinLatLng($coords, '|');

        $this->assertSame('51.5074,-0.1278', $result);
    }

    #[Test]
    public function joinLngLatWithEmptyArray(): void
    {
        $result = Coordinate::joinLngLat([], ';');

        $this->assertSame('', $result);
    }

    #[Test]
    public function joinLatLngWithEmptyArray(): void
    {
        $result = Coordinate::joinLatLng([], '|');

        $this->assertSame('', $result);
    }

    #[Test]
    public function joinLngLatWithDifferentSeparator(): void
    {
        $coords = [
            new LatLng(38.5, -120.2),
            new LatLng(40.7, -120.95),
        ];

        $result = Coordinate::joinLngLat($coords, ':');

        $this->assertSame('-120.2,38.5:-120.95,40.7', $result);
    }
}
