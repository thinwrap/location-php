<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\Enum\ProviderCode;

final class ConnectorErrorTest extends TestCase
{
    #[Test]
    public function constructionWithAllFieldsExposesEverythingAsReadonly(): void
    {
        $cause = ['error' => ['code' => 'foo', 'message' => 'bar']];
        $error = new ConnectorError(
            statusCode: 429,
            providerCode: ProviderCode::RateLimited,
            providerMessage: 'Slow down',
            message: 'Custom message',
            cause: $cause,
        );

        $this->assertSame(429, $error->statusCode);
        $this->assertSame(ProviderCode::RateLimited, $error->providerCode);
        $this->assertSame('Slow down', $error->providerMessage);
        $this->assertSame('Custom message', $error->getMessage());
        $this->assertSame($cause, $error->cause);
    }

    #[Test]
    public function statusCodeAcceptsNullForPreResponseFailures(): void
    {
        $error = new ConnectorError(
            statusCode: null,
            providerCode: ProviderCode::ProviderUnavailable,
            providerMessage: 'connection refused',
        );

        $this->assertNull($error->statusCode);
        $this->assertSame(ProviderCode::ProviderUnavailable, $error->providerCode);
    }

    #[Test]
    public function messageFallsBackToProviderMessage(): void
    {
        $error = new ConnectorError(
            statusCode: 400,
            providerCode: ProviderCode::InvalidRequest,
            providerMessage: 'Missing parameter `q`',
        );

        $this->assertSame('Missing parameter `q`', $error->getMessage());
    }

    #[Test]
    public function messageFallsBackToGenericStringWhenNoProviderMessage(): void
    {
        $error = new ConnectorError(
            statusCode: 500,
            providerCode: ProviderCode::Unknown,
        );

        $this->assertSame('Connector error', $error->getMessage());
        $this->assertNull($error->providerMessage);
        $this->assertNull($error->cause);
    }

    #[Test]
    public function throwableCauseIsPromotedToPreviousAutomatically(): void
    {
        $inner = new \RuntimeException('boom');
        $error = new ConnectorError(
            statusCode: null,
            providerCode: ProviderCode::ProviderUnavailable,
            cause: $inner,
        );

        $this->assertSame($inner, $error->getPrevious());
        $this->assertSame($inner, $error->cause);
    }

    #[Test]
    public function explicitPreviousWinsOverThrowableCause(): void
    {
        $explicit = new \LogicException('explicit');
        $cause = new \RuntimeException('cause');

        $error = new ConnectorError(
            statusCode: null,
            providerCode: ProviderCode::Unknown,
            cause: $cause,
            previous: $explicit,
        );

        $this->assertSame($explicit, $error->getPrevious());
        $this->assertSame($cause, $error->cause);
    }

    #[Test]
    public function causeMayHoldArbitraryVendorBody(): void
    {
        $body = ['errors' => [['detail' => 'x']], 'status' => 'OVER_QUERY_LIMIT'];
        $error = new ConnectorError(
            statusCode: 429,
            providerCode: ProviderCode::RateLimited,
            cause: $body,
        );

        $this->assertSame($body, $error->cause);
        $this->assertNull($error->getPrevious());
    }

    #[Test]
    public function isCatchableAsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);

        throw new ConnectorError(
            statusCode: 401,
            providerCode: ProviderCode::AuthFailed,
        );
    }
}
