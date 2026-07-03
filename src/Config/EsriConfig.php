<?php

declare(strict_types=1);

namespace Thinwrap\Location\Config;

/**
 * Esri (ArcGIS) provider configuration.
 *
 * Supports two mutually-exclusive bearer-style credentials:
 *   - `apiKey`     — long-lived ArcGIS API key.
 *   - `arcgisToken` — short-lived OAuth-issued ArcGIS token (~120-minute
 *                     default lifetime). Token refresh is consumer-owned per
 * design; the connector
 *                     itself remains stateless and accepts a pre-refreshed
 *                     token via this field.
 *
 * Exactly one of the two must be provided. Both forms are forwarded to ESRI
 * via the `token=` query/form param.
 */
final readonly class EsriConfig
{
    public function __construct(
        public ?string $apiKey = null,
        public ?string $arcgisToken = null,
    ) {
        $hasApiKey = $apiKey !== null && $apiKey !== '';
        $hasToken = $arcgisToken !== null && $arcgisToken !== '';

        if (!$hasApiKey && !$hasToken) {
            throw new \InvalidArgumentException(
                'EsriConfig requires one of `apiKey` or `arcgisToken`.',
            );
        }
        if ($hasApiKey && $hasToken) {
            throw new \InvalidArgumentException(
                'EsriConfig `apiKey` and `arcgisToken` are mutually exclusive.',
            );
        }
    }

    /**
     * Resolve the bearer credential to forward to ESRI as `token=`.
     */
    public function bearerToken(): string
    {
        if ($this->apiKey !== null && $this->apiKey !== '') {
            return $this->apiKey;
        }

        // Guaranteed non-null by constructor invariant.
        /** @var string $token */
        $token = $this->arcgisToken;

        return $token;
    }
}
