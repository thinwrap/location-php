<?php

declare(strict_types=1);

namespace Thinwrap\Location\DTO;

use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\Enum\ProviderCode;

final readonly class LatLng
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {}

    /**
     * Accepts a {@see self}, an associative `{lat, lng}` array, or a positional
     * `[lat, lng]` tuple. The wider `array<array-key, mixed>` arm documents that
     * runtime input may be partial/malformed — requires that a missing
     * component throw rather than silently coerce to `0.0` (Null-Island).
     *
     * @param self|array{0: float, 1: float}|array{lat: float, lng: float}|array<array-key, mixed> $input
     */
    public static function from(self|array $input): self
    {
        if ($input instanceof self) {
            return $input;
        }

        // Associative form takes precedence when either component key is present.
        if (array_key_exists('lat', $input) || array_key_exists('lng', $input)) {
            // both components must be present — a missing `lat`/`lng` must
            // NOT silently coerce to `0.0`. Out-of-range but finite values pass
            // through (thin-wrapper; finiteness is checked at the on-wire
            // formatters via assertFinite).
            if (!array_key_exists('lat', $input) || !array_key_exists('lng', $input)) {
                throw new ConnectorError(
                    statusCode: null,
                    providerCode: ProviderCode::InvalidRequest,
                    providerMessage: 'LatLng requires both "lat" and "lng" components',
                );
            }

            // Present-but-non-numeric components must NOT coerce to `0.0`.
            if (!is_numeric($input['lat']) || !is_numeric($input['lng'])) {
                throw new ConnectorError(
                    statusCode: null,
                    providerCode: ProviderCode::InvalidRequest,
                    providerMessage: 'LatLng requires both "lat" and "lng" components',
                );
            }

            return new self((float) $input['lat'], (float) $input['lng']);
        }

        // Positional tuple form: both indices must be present.
        if (!array_key_exists(0, $input) || !array_key_exists(1, $input)) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'LatLng tuple requires both [lat, lng] components',
            );
        }

        // Present-but-non-numeric components must NOT coerce to `0.0`.
        if (!is_numeric($input[0]) || !is_numeric($input[1])) {
            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'LatLng tuple requires both [lat, lng] components',
            );
        }

        return new self((float) $input[0], (float) $input[1]);
    }

    /**
     * / (PINNED): reject NaN/Infinity at the entry boundary before a
     * coordinate reaches the wire. Out-of-range but finite values pass through
     * verbatim (thin-wrapper discipline — no range/swap/Null-Island validation).
     *
     * Mirrors the TS sibling `assertFiniteCoordinate`.
     *
     * @throws ConnectorError `invalid_request` when lat or lng is non-finite.
     */
    public function assertFinite(?string $context = null): void
    {
        if (!is_finite($this->lat) || !is_finite($this->lng)) {
            $where = $context !== null ? " ({$context})" : '';

            throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Coordinate lat/lng must be finite numbers{$where}",
            );
        }
    }

    /**
     * Format the longitude as a wire string, guarding finiteness first.
     * Cross-language parity name: `formatLng` (paired with {@see formatLat}).
     */
    public function formatLng(): string
    {
        $this->assertFinite('formatLng');

        return self::fmtCoord($this->lng);
    }

    /**
     * Format the latitude as a wire string, guarding finiteness first.
     * Cross-language parity name: `formatLat` (paired with {@see formatLng}).
     */
    public function formatLat(): string
    {
        $this->assertFinite('formatLat');

        return self::fmtCoord($this->lat);
    }

    /**
     * @param list<self|array{0: float, 1: float}|array{lat: float, lng: float}> $inputs
     * @return list<self>
     */
    public static function fromList(array $inputs): array
    {
        return array_map(self::from(...), $inputs);
    }

    public function toLatLngString(): string
    {
        $this->assertFinite('toLatLngString');

        return self::fmtCoord($this->lat) . ',' . self::fmtCoord($this->lng);
    }

    public function toLngLatString(): string
    {
        $this->assertFinite('toLngLatString');

        return self::fmtCoord($this->lng) . ',' . self::fmtCoord($this->lat);
    }

    /**
     * Format a single coordinate component as a fixed-notation wire string.
     *
     * PHP's default `(string) $float` cast emits scientific notation for
     * near-zero magnitudes (e.g. `(string) 0.00001 === "1.0E-5"`), which
     * location providers reject in a URL/param and which diverges from the
     * JS/Go/Python siblings' fixed-point wire format. `%.8F` forces fixed
     * notation (8 fractional digits ≈ ~1 mm at the equator — well beyond any
     * geographic precision); trailing zeros and a bare trailing `.` are trimmed
     * so normal coordinates are byte-identical to the old cast
     * (`0.00001` → `"0.00001"`, `40.7128` → `"40.7128"`, `-74.0` → `"-74"`).
     *
     * Callers guard finiteness via {@see assertFinite()} first, so NaN/INF
     * never reach here.
     */
    private static function fmtCoord(float $v): string
    {
        // Whole-number fast-path keeps integral coordinates compact ("-74" not "-74.0").
        if ($v === floor($v) && abs($v) < 1e15) {
            return (string) (int) $v;
        }

        return rtrim(rtrim(sprintf('%.8F', $v), '0'), '.');
    }
}
