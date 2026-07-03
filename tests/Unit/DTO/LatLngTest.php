<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\DTO;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Location\DTO\LatLng;

final class LatLngTest extends TestCase
{
    #[Test]
    public function constructionSetsLatAndLng(): void
    {
        $latLng = new LatLng(40.7128, -74.0060);

        $this->assertSame(40.7128, $latLng->lat);
        $this->assertSame(-74.0060, $latLng->lng);
    }

    #[Test]
    public function toLatLngStringReturnsLatCommaLng(): void
    {
        $latLng = new LatLng(38.5, -120.2);

        $this->assertSame('38.5,-120.2', $latLng->toLatLngString());
    }

    #[Test]
    public function toLngLatStringReturnsLngCommaLat(): void
    {
        $latLng = new LatLng(38.5, -120.2);

        $this->assertSame('-120.2,38.5', $latLng->toLngLatString());
    }

    #[Test]
    public function toLatLngStringWithIntegerLikeValues(): void
    {
        $latLng = new LatLng(0.0, 0.0);

        $this->assertSame('0,0', $latLng->toLatLngString());
    }

    #[Test]
    public function toLngLatStringWithNegativeCoordinates(): void
    {
        $latLng = new LatLng(-33.8688, 151.2093);

        $this->assertSame('151.2093,-33.8688', $latLng->toLngLatString());
    }

    #[Test]
    public function fromReturnsLatLngObjectAsIs(): void
    {
        $original = new LatLng(32.08, 34.78);

        $this->assertSame($original, LatLng::from($original));
    }

    #[Test]
    public function fromAcceptsPositionalArray(): void
    {
        $latLng = LatLng::from([32.08, 34.78]);

        $this->assertSame(32.08, $latLng->lat);
        $this->assertSame(34.78, $latLng->lng);
    }

    #[Test]
    public function fromAcceptsAssociativeArray(): void
    {
        $latLng = LatLng::from(['lat' => 32.08, 'lng' => 34.78]);

        $this->assertSame(32.08, $latLng->lat);
        $this->assertSame(34.78, $latLng->lng);
    }

    #[Test]
    public function fromListNormalizesMixedInputs(): void
    {
        $list = LatLng::fromList([
            new LatLng(32.08, 34.78),
            [32.11, 34.85],
            ['lat' => 31.77, 'lng' => 35.21],
        ]);

        $this->assertCount(3, $list);
        $this->assertSame(32.08, $list[0]->lat);
        $this->assertSame(34.78, $list[0]->lng);
        $this->assertSame(32.11, $list[1]->lat);
        $this->assertSame(34.85, $list[1]->lng);
        $this->assertSame(31.77, $list[2]->lat);
        $this->assertSame(35.21, $list[2]->lng);
    }
}
