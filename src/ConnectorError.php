<?php

declare(strict_types=1);

namespace Thinwrap\Location;

use Thinwrap\Location\Enum\ProviderCode;

/**
 * Unified error type for all connector failures.
 *
 * Mirrors the TS `ConnectorError` (cross-language parity). There
 * is no structured `retryAfterSeconds` field — wrappers perform no retry; any
 * Retry-After signal is surfaced via parsed seconds in `providerMessage` and
 * the raw header value via the `cause` payload by design.
 */
class ConnectorError extends \RuntimeException
{
    /**
     * @param int|null $statusCode HTTP status code, or null for pre-response failures (e.g. network errors).
     * @param ProviderCode $providerCode Canonical normalized error category (required).
     * @param string|null $providerMessage Raw provider-supplied error message, if any.
     * @param string|null $message Human-readable message; falls back to `providerMessage` then a generic string.
     * @param mixed $cause Vendor body (array/object/string) or a Throwable. PHP equivalent of TS `unknown`.
     * @param \Throwable|null $previous Underlying exception, if applicable.
     */
    public function __construct(
        public readonly ?int $statusCode,
        public readonly ProviderCode $providerCode,
        public readonly ?string $providerMessage = null,
        ?string $message = null,
        public readonly mixed $cause = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? $providerMessage ?? 'Connector error',
            0,
            $previous instanceof \Throwable
                ? $previous
                : ($cause instanceof \Throwable ? $cause : null),
        );
    }
}
