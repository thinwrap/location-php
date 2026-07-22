<?php

declare(strict_types=1);

namespace Thinwrap\Location\Connector\Esri;

use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\Enum\ProviderCode;
use Thinwrap\Location\Enum\TravelMode;

/**
 * Shared ESRI travel-mode translation (mirrors TS `esri.travel-modes.ts`).
 *
 * ArcGIS Network Analyst REST services (`Route/solve`,
 * `ServiceArea/solveServiceArea`, `OriginDestinationCostMatrix/solveODCostMatrix`)
 * require the `travelMode` parameter to be a **full JSON object**, not a name
 * string — a bare `"Walking"` is ignored and the service stays on driving. The
 * wrapper embeds the canonical object so a consumer only ever writes the
 * normalized `TravelMode::Walking`.
 *
 * Verified live against `route-api.arcgis.com` on 2026-07-21 for routing,
 * isochrone (service area) and OD cost matrix. Source: ArcGIS REST API Route
 * service `travelMode` reference.
 */
final class EsriTravelModes
{
    /**
     * Time-impedance column names ESRI reports for the travel modes this wrapper
     * requests. The OD Cost Matrix names its cost columns after the active
     * impedance (`TravelTime` for driving, `WalkTime` for walking), so the matrix
     * decoder locates the time column by trying these in order.
     *
     * @var list<string>
     */
    public const TIME_ATTRIBUTE_NAMES = ['TravelTime', 'WalkTime'];

    /**
     * Canonical ArcGIS World "Walking Time" travel-mode object. Setting it makes
     * the service override its impedance to `WalkTime` (so route summaries carry
     * `Total_WalkTime` and the OD matrix reports `costAttributeNames`
     * `['WalkTime', …]`; the per-connector normalizers read those
     * travel-mode-independently).
     *
     * @return array<string,mixed>
     */
    public static function walkingTravelMode(): array
    {
        return [
            'attributeParameterValues' => [
                [
                    'parameterName' => 'Restriction Usage',
                    'attributeName' => 'Walking',
                    'value' => 'PROHIBITED',
                ],
                [
                    'parameterName' => 'Restriction Usage',
                    'attributeName' => 'Preferred for Pedestrians',
                    'value' => 'PREFER_LOW',
                ],
                [
                    'parameterName' => 'Walking Speed (km/h)',
                    'attributeName' => 'WalkTime',
                    'value' => 5,
                ],
            ],
            'description' => 'Follows paths and roads that allow pedestrian traffic and finds solutions that optimize travel time. The walking speed is set to 5 kilometers per hour.',
            'impedanceAttributeName' => 'WalkTime',
            'simplificationToleranceUnits' => 'esriMeters',
            'uturnAtJunctions' => 'esriNFSBAllowBacktrack',
            'restrictionAttributeNames' => [
                'Avoid Private Roads',
                'Avoid Roads Unsuitable for Pedestrians',
                'Preferred for Pedestrians',
                'Walking',
            ],
            'useHierarchy' => false,
            'simplificationTolerance' => 2,
            'timeAttributeName' => 'WalkTime',
            'distanceAttributeName' => 'Kilometers',
            'type' => 'WALK',
            'id' => 'caFAgoThrvUpkFBW',
            'name' => 'Walking Time',
        ];
    }

    /**
     * Translate the normalized {@see TravelMode} to the ESRI wire `travelMode`
     * form value — a JSON-encoded {@see walkingTravelMode()} object for
     * `Walking`, or `null` for `Driving`/unset (the services default to
     * "Driving Time"). `Cycling` throws `unsupported_travel_mode`: ArcGIS World
     * network services ship no public cycling mode. `$op` names the operation
     * for the error message.
     */
    public static function map(?TravelMode $mode, string $op): ?string
    {
        return match ($mode) {
            TravelMode::Walking => json_encode(self::walkingTravelMode(), JSON_THROW_ON_ERROR),
            TravelMode::Cycling => throw new ConnectorError(
                statusCode: null,
                providerCode: ProviderCode::UnsupportedTravelMode,
                providerMessage: sprintf('ESRI %s does not support travelMode "cycling"', $op),
            ),
            TravelMode::Driving, null => null,
        };
    }
}
