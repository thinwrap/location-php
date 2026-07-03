<?php

declare(strict_types=1);

namespace Thinwrap\Location\Util;

use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\Enum\ProviderCode;

/**
 * Polyline encode/decode utilities — byte-for-byte parity with the TS reference.
 *
 * Implementations use PHP stdlib only (`chr`, `ord`, `round`, bit-ops). No
 * extension dependencies. Output of {@see encodePolyline} is byte-identical
 * to `encodePolyline` in `@thinwrap/location` for the same input.
 */
final class Polyline
{
    /**
     * Encode an array of LatLng coordinates into a Google-format precision-5 polyline string.
     *
     * @param list<LatLng> $coords
     */
    public static function encodePolyline(array $coords): string
    {
        $output = '';
        $prevLat = 0;
        $prevLng = 0;

        foreach ($coords as $coord) {
            // reject non-finite coordinates before encoding (mirrors TS).
            if (!is_finite($coord->lat) || !is_finite($coord->lng)) {
                throw new ConnectorError(
                    statusCode: null,
                    providerCode: ProviderCode::InvalidRequest,
                    providerMessage: 'Cannot encode polyline: coordinate lat/lng must be finite numbers',
                );
            }
            $lat = (int) round($coord->lat * 1e5);
            $lng = (int) round($coord->lng * 1e5);

            $output .= self::encodeSignedValue($lat - $prevLat);
            $output .= self::encodeSignedValue($lng - $prevLng);

            $prevLat = $lat;
            $prevLng = $lng;
        }

        return $output;
    }

    /**
     * Decode a Google-format precision-5 polyline string into LatLng coordinates.
     *
     * @return list<LatLng>
     */
    public static function decodePolyline(string $encoded): array
    {
        $coords = [];
        $index = 0;
        $lat = 0;
        $lng = 0;
        $length = strlen($encoded);

        while ($index < $length) {
            [$latValue, $index] = self::decodeSignedValue($encoded, $index);
            $lat += $latValue;

            [$lngValue, $index] = self::decodeSignedValue($encoded, $index);
            $lng += $lngValue;

            $coords[] = new LatLng($lat / 1e5, $lng / 1e5);
        }

        return $coords;
    }

    /**
     * Decode a HERE flex-polyline encoded string into LatLng coordinates.
     *
     * Reference: https://github.com/heremaps/flexible-polyline
     * Altitude (3rd dimension) is silently dropped — only lat/lng returned (parity with TS).
     *
     * @return list<LatLng>
     */
    public static function decodeFlexPolyline(string $encoded): array
    {
        /** @var list<int> $decodingTable */
        $decodingTable = [
            62, -1, -1, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, -1, -1, -1, -1, -1, -1, -1,
            0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21,
            22, 23, 24, 25, -1, -1, -1, -1, 63, -1, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35,
            36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51,
        ];

        $length = strlen($encoded);

        // Unsigned varint decoder — used for the two header values per the HERE spec.
        /** @var \Closure(int): array{int, int} $decodeUnsigned */
        $decodeUnsigned = static function (int $idx) use ($encoded, $decodingTable, $length): array {
            $result = 0;
            $shift = 0;
            do {
                if ($idx >= $length) {
                    throw new ConnectorError(
                        statusCode: null,
                        providerCode: ProviderCode::Unknown,
                        providerMessage: 'Malformed polyline',
                    );
                }
                $tableIndex = ord($encoded[$idx]) - 45;
                $b = ($tableIndex >= 0 && $tableIndex < count($decodingTable))
                    ? $decodingTable[$tableIndex]
                    : -1;
                if ($b < 0) {
                    throw new ConnectorError(
                        statusCode: null,
                        providerCode: ProviderCode::Unknown,
                        providerMessage: 'Malformed polyline',
                    );
                }
                $idx++;
                $result |= ($b & 0x1F) << $shift;
                $shift += 5;
            } while ($b >= 0x20);

            return [$result, $idx];
        };

        // Signed (ZigZag) varint decoder — used for the lat/lng/alt body deltas.
        /** @var \Closure(int): array{int, int} $decodeValue */
        $decodeValue = static function (int $idx) use ($decodeUnsigned): array {
            [$value, $nextIdx] = $decodeUnsigned($idx);

            return [($value & 1) !== 0 ? ~($value >> 1) : ($value >> 1), $nextIdx];
        };

        if ($length === 0) {
            return [];
        }

        // Header per https://github.com/heremaps/flexible-polyline:
        //   value 1 → format version (always 1 in v1); decoded unsigned, then discarded.
        //   value 2 → (thirdDimType << 4) | precision (with 3D precision above bit 6).
        // Both header values are unsigned varints (NOT ZigZag-decoded).
        $idx = 0;
        [, $idx] = $decodeUnsigned($idx);

        [$header2, $idx] = $decodeUnsigned($idx);
        $precision = $header2 & 0x0F;
        $hasThirdDim = ($header2 >> 4) & 0x07;

        $factor = 10 ** $precision;
        $coords = [];
        $lat = 0;
        $lng = 0;

        while ($idx < $length) {
            [$latDelta, $idx] = $decodeValue($idx);
            $lat += $latDelta;

            [$lngDelta, $idx] = $decodeValue($idx);
            $lng += $lngDelta;

            if ($hasThirdDim) {
                [, $idx] = $decodeValue($idx);
            }

            $coords[] = new LatLng($lat / $factor, $lng / $factor);
        }

        return $coords;
    }

    /**
     * Build an ESRI-JSON `paths` geometry object from LatLng-coordinate paths.
     *
     * Each inner LatLng becomes an ESRI `[lng, lat]` pair (ESRI's convention; opposite
     * of GeoJSON). The `spatialReference` is fixed at `{ wkid: 4326 }` (WGS 84 — the
     * only CRS used in thinwrap/location).
     *
     * Mirrors the corrected signature: `LatLng[][] -> EsriPathsGeometry`.
     *
     * @param list<list<LatLng>> $paths
     * @return array{paths: list<list<list<float>>>, spatialReference: array{wkid: int}}
     */
    public static function encodeEsriPaths(array $paths): array
    {
        $esriPaths = [];
        foreach ($paths as $path) {
            $pathOut = [];
            foreach ($path as $point) {
                $pathOut[] = [$point->lng, $point->lat];
            }
            $esriPaths[] = $pathOut;
        }

        return [
            'paths' => $esriPaths,
            'spatialReference' => ['wkid' => 4326],
        ];
    }

    private static function encodeSignedValue(int $value): string
    {
        $v = $value < 0 ? ~($value << 1) : ($value << 1);
        $output = '';
        while ($v >= 0x20) {
            $output .= chr((0x20 | ($v & 0x1F)) + 63);
            $v >>= 5;
        }
        $output .= chr($v + 63);

        return $output;
    }

    /**
     * @return array{int, int}
     */
    private static function decodeSignedValue(string $encoded, int $index): array
    {
        $length = strlen($encoded);
        $result = 0;
        $shift = 0;
        do {
            if ($index >= $length) {
                throw new ConnectorError(
                    statusCode: null,
                    providerCode: ProviderCode::Unknown,
                    providerMessage: 'Malformed polyline',
                );
            }
            $b = ord($encoded[$index]) - 63;
            if ($b < 0) {
                throw new ConnectorError(
                    statusCode: null,
                    providerCode: ProviderCode::Unknown,
                    providerMessage: 'Malformed polyline',
                );
            }
            $index++;
            $result |= ($b & 0x1F) << $shift;
            $shift += 5;
        } while ($b >= 0x20);

        return [($result & 1) !== 0 ? ~($result >> 1) : ($result >> 1), $index];
    }
}
