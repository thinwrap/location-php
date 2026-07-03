<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\DTO;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Enum\ProviderCode;

/**
 * Coverage for LatLng::from() coercion + the "no Null-Island" guards.
 */
final class LatLngFromTest extends TestCase
{
    #[Test]
    public function fromSelfReturnsSameInstance(): void
    {
        $ll = new LatLng(1.0, 2.0);
        self::assertSame($ll, LatLng::from($ll));
    }

    #[Test]
    public function fromAssociativeArray(): void
    {
        $ll = LatLng::from(['lat' => 32.0, 'lng' => 34.0]);
        self::assertSame(32.0, $ll->lat);
        self::assertSame(34.0, $ll->lng);
    }

    #[Test]
    public function fromPositionalTuple(): void
    {
        $ll = LatLng::from([32.0, 34.0]);
        self::assertSame(32.0, $ll->lat);
        self::assertSame(34.0, $ll->lng);
    }

    #[Test]
    public function fromAssociativeMissingLngThrows(): void
    {
        try {
            LatLng::from(['lat' => 1.0]);
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertStringContainsString('both', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function fromAssociativeMissingLatThrows(): void
    {
        $this->expectException(ConnectorError::class);
        LatLng::from(['lng' => 2.0]);
    }

    #[Test]
    public function fromTupleMissingSecondComponentThrows(): void
    {
        try {
            LatLng::from([1.0]);
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertStringContainsString('tuple', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function fromEmptyArrayThrows(): void
    {
        $this->expectException(ConnectorError::class);
        LatLng::from([]);
    }

    #[Test]
    public function fromAssociativeNonNumericComponentThrows(): void
    {
        // Present-but-non-numeric associative component must NOT coerce to 0.0.
        try {
            LatLng::from(['lat' => 'abc', 'lng' => '1.0']);
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertStringContainsString('both', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function fromTupleNonNumericComponentThrows(): void
    {
        // Present-but-non-numeric positional component must NOT coerce to 0.0.
        try {
            LatLng::from(['abc', 1.0]);
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertStringContainsString('tuple', (string) $e->providerMessage);
        }
    }
}
