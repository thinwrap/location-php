<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Util\Polyline;

/**
 * Error/edge coverage for the polyline codec (the happy-path round-trips live
 * in PolylineParityTest).
 */
final class PolylineEdgeTest extends TestCase
{
    #[Test]
    public function encodeRejectsNonFiniteCoordinate(): void
    {
        try {
            Polyline::encodePolyline([new LatLng(INF, 0.0)]);
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
        }
    }

    #[Test]
    public function encodeRejectsNanCoordinate(): void
    {
        $this->expectException(ConnectorError::class);
        Polyline::encodePolyline([new LatLng(10.0, NAN)]);
    }

    #[Test]
    public function decodeThrowsOnInvalidCharacter(): void
    {
        // ' ' (0x20) is below the 0x3F offset -> negative 5-bit group.
        $this->expectException(ConnectorError::class);
        Polyline::decodePolyline(' ');
    }

    #[Test]
    public function decodeThrowsOnTruncatedContinuation(): void
    {
        // '~' sets the continuation bit but has no following byte.
        $this->expectException(ConnectorError::class);
        Polyline::decodePolyline('~');
    }

    #[Test]
    public function decodeFlexPolylineEmptyReturnsEmptyList(): void
    {
        self::assertSame([], Polyline::decodeFlexPolyline(''));
    }

    #[Test]
    public function decodeFlexPolylineThrowsOnMalformedInput(): void
    {
        $this->expectException(ConnectorError::class);
        Polyline::decodeFlexPolyline('!');
    }

    #[Test]
    public function encodeEmptyCoordsReturnsEmptyString(): void
    {
        self::assertSame('', Polyline::encodePolyline([]));
    }
}
