<?php

declare(strict_types=1);

namespace Thinwrap\Location\Enum;

/**
 * Canonical normalized error codes shared across thinwrap scopes, plus the
 * five location-extended values defined for location scope.
 *
 * String backing values match the TS `ProviderCode` union literals exactly
 * (cross-language parity's location analogue).
 */
enum ProviderCode: string
{
    // 6 notifications-canonical (shared across thinwrap scopes)
    case InvalidRecipient    = 'invalid_recipient';
    case RateLimited         = 'rate_limited';
    case AuthFailed          = 'auth_failed';
    case ProviderUnavailable = 'provider_unavailable';
    case InvalidRequest      = 'invalid_request';
    case Unknown             = 'unknown';

    // 5 location-extended
    case UnsupportedField      = 'unsupported_field';
    case UnsupportedOption     = 'unsupported_option';
    case UnsupportedTravelMode = 'unsupported_travel_mode';
    case ProfileNotConfigured  = 'profile_not_configured';
    case MatrixPollingTimeout  = 'matrix_polling_timeout';
}
