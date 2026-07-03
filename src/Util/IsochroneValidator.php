<?php

declare(strict_types=1);

namespace Thinwrap\Location\Util;

use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\Isochrone\IsochroneOptions;
use Thinwrap\Location\Enum\ProviderCode;

/**
 * Pre-flight cap validation for isochrone requests.
 *
 * Mirrors the TS `validateIsochroneCap` helper. The baseline coverage
 * discipline (≥90% rule) accepts up to four contour `values` per request —
 * the lowest common denominator across Mapbox, HERE, Esri, and TomTom
 * Isochrone offerings. Each provider connector calls this validator
 * before dispatching, so the cap is enforced uniformly regardless of
 * which provider was selected on the facade.
 */
final class IsochroneValidator
{
    public const MAX_CONTOUR_VALUES = 4;

    /**
     * Throws `ConnectorError(providerCode: ProviderCode::InvalidRequest)`
     * when `$options->values` exceeds {@see MAX_CONTOUR_VALUES}.
     *
     * @throws ConnectorError when the contour cap is exceeded.
     */
    public static function validateCap(IsochroneOptions $options): void
    {
        $count = count($options->values);
        if ($count < 1) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'isochrone requires at least one break value',
            );
        }
        foreach ($options->values as $value) {
            if (!is_finite($value) || $value <= 0) {
                throw new ConnectorError(
                    statusCode: null,
                    providerCode: ProviderCode::InvalidRequest,
                    providerMessage: 'isochrone break values must be finite numbers greater than 0',
                );
            }
        }
        if ($count > self::MAX_CONTOUR_VALUES) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: sprintf(
                    'Isochrone contour cap exceeded: %d values provided, maximum is %d.',
                    $count,
                    self::MAX_CONTOUR_VALUES,
                ),
            );
        }
    }
}
