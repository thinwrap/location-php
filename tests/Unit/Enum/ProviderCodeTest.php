<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Location\Enum\ProviderCode;

final class ProviderCodeTest extends TestCase
{
    #[Test]
    public function exposesAllElevenCanonicalAndLocationExtendedCases(): void
    {
        $values = array_map(static fn(ProviderCode $c): string => $c->value, ProviderCode::cases());

        $this->assertSame(
            [
                // 6 notifications-canonical
                'invalid_recipient',
                'rate_limited',
                'auth_failed',
                'provider_unavailable',
                'invalid_request',
                'unknown',
                // 5 location-extended
                'unsupported_field',
                'unsupported_option',
                'unsupported_travel_mode',
                'profile_not_configured',
                'matrix_polling_timeout',
            ],
            $values,
        );
    }

    #[Test]
    public function fromValueRoundTripsForEachCase(): void
    {
        foreach (ProviderCode::cases() as $case) {
            $this->assertSame($case, ProviderCode::from($case->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        $this->assertNull(ProviderCode::tryFrom('definitely_not_a_real_code'));
    }

    #[Test]
    public function backingValuesMatchTsUnionLiteralsExactly(): void
    {
        // Cross-language parity (audit anchor): backing
        // values are the same snake_case strings as the TS string-literal union.
        $this->assertSame('invalid_recipient', ProviderCode::InvalidRecipient->value);
        $this->assertSame('rate_limited', ProviderCode::RateLimited->value);
        $this->assertSame('auth_failed', ProviderCode::AuthFailed->value);
        $this->assertSame('provider_unavailable', ProviderCode::ProviderUnavailable->value);
        $this->assertSame('invalid_request', ProviderCode::InvalidRequest->value);
        $this->assertSame('unknown', ProviderCode::Unknown->value);
        $this->assertSame('unsupported_field', ProviderCode::UnsupportedField->value);
        $this->assertSame('unsupported_option', ProviderCode::UnsupportedOption->value);
        $this->assertSame('unsupported_travel_mode', ProviderCode::UnsupportedTravelMode->value);
        $this->assertSame('profile_not_configured', ProviderCode::ProfileNotConfigured->value);
        $this->assertSame('matrix_polling_timeout', ProviderCode::MatrixPollingTimeout->value);
    }
}
