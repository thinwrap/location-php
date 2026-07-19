# HERE Connectors (PHP)

HERE Location Services connectors for routing, distance matrix, geocoding, and isochrone via direct HTTP calls.

## Quick install

See the [package README](../../../README.md) for installation. Dispatches when `LocationProviderId::Here` is passed to a facade.

```php
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Config\HereConfig;
use Thinwrap\Location\Routing;
use Thinwrap\Location\Matrix;
use Thinwrap\Location\Geocoding;
use Thinwrap\Location\Isochrone;

$cfg = new HereConfig(apiKey: getenv('HERE_KEY'));
$routing = new Routing(LocationProviderId::Here, $cfg);
$matrix  = new Matrix(LocationProviderId::Here,  $cfg);
$geo     = new Geocoding(LocationProviderId::Here, $cfg);
$iso     = new Isochrone(LocationProviderId::Here, $cfg);
```

## Configuration

| Field | Type | Required | Notes |
|---|---|---|---|
| `apiKey` | `string` | yes | HERE API key (REST) — single key works across Router v8, Matrix v8, Geocode/Revgeocode/Autocomplete, Isolines v8 |
## Auth setup

Provision a project at https://platform.here.com/ and create a REST API key. Sent as `apiKey=` query param on every request. Static — no refresh.

## Vendor docs

- Routing v8: https://www.here.com/docs/bundle/routing-api-v8-api-reference/page/index.html
- Matrix Routing v8: https://www.here.com/docs/bundle/matrix-routing-api-v8-api-reference/page/index.html
- Geocoding & Search: https://www.here.com/docs/bundle/geocoding-and-search-api-v7-api-reference/page/index.html
- Isoline Routing v8: https://www.here.com/docs/bundle/isoline-routing-api-v8-api-reference/page/index.html
- Pricing & rate limits: https://www.here.com/pricing

## Routing

### Endpoints

- Standard routing: `GET https://router.hereapi.com/v8/routes`
- Waypoint sequence: `GET https://wps.hereapi.com/v8/findsequence2`

### Narrowed input augmentations

`optimize: true` triggers the two-step `findsequence2` → `routes` flow. Travel mode maps to HERE `transportMode`. Intermediate waypoints are added as `via=lat,lng` query parameters. See `src/Providers/Here/DTO/HereRoutingOptions.php` for the per-provider narrowed input shape.

### Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 | (any) | `AuthFailed` |
| 403 | (any) | `AuthFailed` |
| 400 | (any) | `InvalidRequest` |
| 429 | (respects `Retry-After`) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |

### Retry-After

On HTTP 429, `ConnectorError->cause['retryAfter']` carries the raw header; parsed seconds in `providerMessage`. No structured `retryAfterSeconds` field.

## Matrix

### Endpoint

`POST https://matrix.router.hereapi.com/v8/matrix?async=true` → poll status → retrieve.

### Narrowed input augmentations

`transportMode?: 'car' | 'truck' | 'pedestrian' | 'bicycle' | 'scooter'` overrides the base `travelMode` mapping. Polling parameters surfaced via `_passthrough.body.timeoutMs`.

## Geocoding

### Endpoints

- Forward: `GET https://geocode.search.hereapi.com/v1/geocode`
- Reverse: `GET https://revgeocode.search.hereapi.com/v1/revgeocode`
- Autocomplete: `GET https://autosuggest.search.hereapi.com/v1/autosuggest`

## Isochrone

### Endpoint

`GET https://isoline.router.hereapi.com/v8/isolines`

### Narrowed input augmentations

Standard `IsochroneOptions`. `IsochroneType::Time | IsochroneType::Distance` maps to HERE `range[type]=time|distance`.
