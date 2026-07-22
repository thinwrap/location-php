# TomTom Connectors (PHP)

TomTom Maps connectors for routing, distance matrix, geocoding, and isochrone (reachable range) via direct HTTP calls.

## Quick install

See the [package README](../../../README.md) for installation. Dispatches when `LocationProviderId::TomTom` is passed to a facade.

```php
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Config\TomTomConfig;
use Thinwrap\Location\Routing;
use Thinwrap\Location\Matrix;
use Thinwrap\Location\Geocoding;
use Thinwrap\Location\Isochrone;

$cfg = new TomTomConfig(apiKey: getenv('TOMTOM_KEY'));
$routing = new Routing(LocationProviderId::TomTom, $cfg);
$matrix  = new Matrix(LocationProviderId::TomTom,  $cfg);
$geo     = new Geocoding(LocationProviderId::TomTom, $cfg);
$iso     = new Isochrone(LocationProviderId::TomTom, $cfg);
```

## Configuration

| Field | Type | Required | Notes |
|---|---|---|---|
| `apiKey` | `string` | yes | TomTom API key — works across Routing v1, Matrix v2, Search v2, Reachable Range v1 |

## Auth setup

Create a key at https://developer.tomtom.com/user/me/apps. Sent as `key=` query param on every request. Static — no refresh.

## Vendor docs

- Routing: https://developer.tomtom.com/routing-api/documentation/tomtom-maps/calculate-route
- Matrix Routing: https://developer.tomtom.com/matrix-routing-v2-api/documentation/synchronous-matrix
- Geocoding: https://developer.tomtom.com/geocoding-api/documentation/geocode
- Reachable Range: https://developer.tomtom.com/routing-api/documentation/tomtom-maps/calculate-reachable-range
- Rate limits: https://developer.tomtom.com/

## Routing

### Endpoint

`GET https://api.tomtom.com/routing/1/calculateRoute/{locations}/json`

### Narrowed input augmentations

Standard `RoutingOptions`. `optimize: true` maps to `computeBestOrder=true`.

### Error mapping

| Vendor HTTP | `ProviderCode` |
|---|---|
| 400 | `InvalidRequest` |
| 401 / 403 | `AuthFailed` |
| 429 (respects `Retry-After`) | `RateLimited` |
| 5xx | `ProviderUnavailable` |

### Retry-After

On HTTP 429, `ConnectorError->cause['retryAfter']` carries the raw header; parsed seconds in `providerMessage`. No structured `retryAfterSeconds` field.

## Matrix

### Endpoint

`POST https://api.tomtom.com/routing/matrix/2`

### Narrowed input augmentations

Standard `MatrixOptions`. Cycling travel mode raises `ConnectorError` with `providerCode: ProviderCode::UnsupportedTravelMode` if TomTom rejects the request.

## Geocoding

### Endpoints

- Forward: `GET https://api.tomtom.com/search/2/geocode/{query}.json`
- Reverse: `GET https://api.tomtom.com/search/2/reverseGeocode/{lat},{lng}.json`
- Autocomplete (Fuzzy Search): `GET https://api.tomtom.com/search/2/search/{query}.json`

## Isochrone

### Endpoint

`GET https://api.tomtom.com/routing/1/calculateReachableRange/{lat},{lng}/json`

### Narrowed input augmentations

Standard `IsochroneOptions`. `IsochroneType::Time` ⇒ `timeBudgetInSec=`; `IsochroneType::Distance` ⇒ `distanceBudgetInMeters=`. Multi-value calls fan out via parallel requests.
