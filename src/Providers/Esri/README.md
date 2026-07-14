---
providerId: esri
operations:
  routing:
    auth:
      method: arcgis-token
      tokenLifecycle: refreshable
    endpoint:
      default: https://route-api.arcgis.com/arcgis/rest/services/World/Route/NAServer/Route_World/solve
    versioning:
      vendorApiVersion: NAServer-2024
      lastVerified: 2026-05-17
    selfHostable: true
    rateLimitDocsUrl: https://developers.arcgis.com/documentation/mapping-and-location-services/security-and-authentication/
    retryAfterSurfaced: false
    notes_passthrough: |
      Form-encoded POST (not JSON). ESRI returns kilometers and minutes which
      the connector normalizes to meters/seconds. ESRI may respond HTTP 200
      with an `error` field on validation failure — the connector inspects this.
      Forward `directionsLanguage`, `directionsStyleName`, `findBestSequence`,
      `preserveTerminalStops` via `_passthrough.body`.
  matrix:
    auth:
      method: arcgis-token
      tokenLifecycle: refreshable
    endpoint:
      default: https://logistics.arcgis.com/arcgis/rest/services/World/OriginDestinationCostMatrix/GPServer/GenerateOriginDestinationCostMatrix/submitJob
    versioning:
      vendorApiVersion: GPServer-2024
      lastVerified: 2026-05-17
    selfHostable: true
    rateLimitDocsUrl: https://developers.arcgis.com/documentation/mapping-and-location-services/security-and-authentication/
    retryAfterSurfaced: false
    notes_passthrough: |
      Form-encoded POST. ESRI OD Cost Matrix returns miles + minutes; connector
      normalizes to meters + seconds. OIDs are 1-based. Forward
      `outputType`, `cutoff`, `targetDestinationCount` via `_passthrough.body`.
  geocoding:
    auth:
      method: arcgis-token
      tokenLifecycle: refreshable
    endpoint:
      default: https://geocode-api.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates
    versioning:
      vendorApiVersion: GeocodeServer-2024
      lastVerified: 2026-05-17
    selfHostable: true
    rateLimitDocsUrl: https://developers.arcgis.com/documentation/mapping-and-location-services/security-and-authentication/
    retryAfterSurfaced: false
    notes_passthrough: |
      Three endpoints: findAddressCandidates / reverseGeocode / suggest.
      Forward `category`, `countryCode`, `location`, `magicKey`, `outFields`
      via `_passthrough.query`.
  isochrone:
    auth:
      method: arcgis-token
      tokenLifecycle: refreshable
    endpoint:
      default: https://route-api.arcgis.com/arcgis/rest/services/World/ServiceAreas/NAServer/ServiceArea_World/solveServiceArea
    versioning:
      vendorApiVersion: NAServer-2024
      lastVerified: 2026-05-17
    selfHostable: true
    rateLimitDocsUrl: https://developers.arcgis.com/documentation/mapping-and-location-services/security-and-authentication/
    retryAfterSurfaced: false
    notes_passthrough: |
      Form-encoded POST. Service Area expects minutes / miles; connector
      converts seconds → minutes and meters → miles on the wire. Returns
      ESRI geometry which the connector emits as GeoJSON Polygons. Forward
      `travelDirection`, `overlapPolicy`, `splitPolygonsAtBreaks` via
      `_passthrough.body`.
---

# ESRI ArcGIS Connectors (PHP)

ESRI ArcGIS Location Services connectors for routing, distance matrix, geocoding, and isochrone (service areas) via direct HTTP calls.

## Quick install

See the [package README](../../../README.md) for installation. Dispatches when `LocationProviderId::Esri` is passed to a facade.

```php
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Config\EsriConfig;
use Thinwrap\Location\Routing;
use Thinwrap\Location\Matrix;
use Thinwrap\Location\Geocoding;
use Thinwrap\Location\Isochrone;

$cfg = new EsriConfig(apiKey: getenv('ARCGIS_KEY'));
$routing = new Routing(LocationProviderId::Esri, $cfg);
$matrix  = new Matrix(LocationProviderId::Esri,  $cfg);
$geo     = new Geocoding(LocationProviderId::Esri, $cfg);
$iso     = new Isochrone(LocationProviderId::Esri, $cfg);
```

## Configuration

| Field | Type | Required | Notes |
|---|---|---|---|
| `apiKey` | `string` | yes | ArcGIS API key (long-lived) or an OAuth-issued access token |

## Auth setup

Create an API key at https://developers.arcgis.com/api-keys/. Sent as `token=` form field (NAServer endpoints) or query param (GeocodeServer). Token lifecycle: **refreshable** — long-lived API keys, but OAuth-issued tokens require client-side refresh.

ArcGIS Enterprise on-prem deployments are supported by overriding endpoints in `_passthrough.headers`/`query` — point at your tenant's portal URL.

## Vendor docs

- Route service: https://developers.arcgis.com/rest/network/route/
- OD Cost Matrix: https://developers.arcgis.com/rest/network/od-cost-matrix/
- Geocoding service: https://developers.arcgis.com/rest/geocode/
- Service Area: https://developers.arcgis.com/rest/network/service-area/

## Routing

### Endpoint

`POST https://route-api.arcgis.com/arcgis/rest/services/World/Route/NAServer/Route_World/solve` — `application/x-www-form-urlencoded`.

### Narrowed input augmentations

Standard `RoutingOptions`. `optimize: true` maps to `findBestSequence=true`. Path geometry returned as coordinate arrays `[[[lng,lat],...]]`; encoded to standard polyline via `Polyline::encodeEsriPaths()`.

### Error mapping

| Vendor signal | `ProviderCode` |
|---|---|
| HTTP 200 + body `error.code === 498`/`499` | `AuthFailed` |
| HTTP 200 + body `error.code === 400` | `InvalidRequest` |
| HTTP 401 / 403 | `AuthFailed` |
| HTTP 429 | `RateLimited` |
| HTTP 5xx | `ProviderUnavailable` |

### Retry-After

ESRI's API tier may or may not document `Retry-After` (depends on subscription). When present on HTTP 429, surfaced via `cause['retryAfter']` + parsed seconds in `providerMessage`.

## Matrix

### Endpoint

`POST .../GenerateOriginDestinationCostMatrix/submitJob`

### Narrowed input augmentations

Standard `MatrixOptions`. `travelMode` cycling raises `ConnectorError` with `providerCode: ProviderCode::UnsupportedTravelMode` (ESRI's hosted World service doesn't ship a cycling network). Use `_passthrough.body.travelMode` JSON to pass a custom-published travel mode object for ArcGIS Enterprise deployments that provide one.

## Geocoding

### Endpoints

- Forward: `GET .../GeocodeServer/findAddressCandidates`
- Reverse: `GET .../GeocodeServer/reverseGeocode`
- Suggest: `GET .../GeocodeServer/suggest`

> **Reverse geocoding is single-result.** ESRI's `reverseGeocode` returns a
> single best match (not a ranked list), so `reverseGeocode()` yields a
> `candidates[]` array of length 0 or 1 — unlike the multi-candidate reverse
> results from Google/HERE/Mapbox/TomTom. This is an ESRI service characteristic,
> not a wrapper limitation.

## Isochrone

### Endpoint

`POST .../ServiceArea_World/solveServiceArea`

### Narrowed input augmentations

Standard `IsochroneOptions`. `IsochroneType::Time` ⇒ `defaultBreaks` in minutes; `IsochroneType::Distance` ⇒ `defaultBreaks` in miles. Connector handles the unit conversion both directions.
