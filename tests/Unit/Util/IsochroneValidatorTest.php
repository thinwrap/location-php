<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Isochrone\IsochroneOptions;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Enum\IsochroneType;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Util\IsochroneValidator;

/**
 * Coverage for the shared isochrone break-value validator.
 */
final class IsochroneValidatorTest extends TestCase
{
    private function options(array $values): IsochroneOptions
    {
        return new IsochroneOptions(
            center: new LatLng(32.0, 34.0),
            type: IsochroneType::Time,
            values: $values,
        );
    }

    #[Test]
    public function acceptsValidBreakValues(): void
    {
        IsochroneValidator::validateCap($this->options([300, 600]));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsEmptyValues(): void
    {
        try {
            IsochroneValidator::validateCap($this->options([]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertStringContainsString('at least one', (string) $e->providerMessage);
        }
    }

    #[Test]
    public function rejectsNonPositiveValue(): void
    {
        $this->expectException(ConnectorError::class);
        IsochroneValidator::validateCap($this->options([300, 0]));
    }

    #[Test]
    public function rejectsNonFiniteValue(): void
    {
        $this->expectException(ConnectorError::class);
        IsochroneValidator::validateCap($this->options([INF]));
    }

    #[Test]
    public function rejectsMoreThanMaxValues(): void
    {
        try {
            IsochroneValidator::validateCap($this->options([60, 120, 180, 240, 300]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
        }
    }
}
