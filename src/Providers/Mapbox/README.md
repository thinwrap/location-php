# Mapbox Connectors (PHP)

Mapbox connectors for routing, distance matrix, geocoding, and isochrone via direct HTTP calls (no `mapbox/mapbox-sdk-php` SDK).

## Quick install

See the [package README](../../../README.md) for installation. Dispatches when `LocationProviderId::Mapbox` is passed to a facade.

```php
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Config\MapboxConfig;
use Thinwrap\Location\Routing;
use Thinwrap\Location\Matrix;
use Thinwrap\Location\Geocoding;
use Thinwrap\Location\Isochrone;

$cfg = new MapboxConfig(accessToken: getenv('MAPBOX_TOKEN'));
$routing = new Routing(LocationProviderId::Mapbox, $cfg);
$matrix  = new Matrix(LocationProviderId::Mapbox,  $cfg);
$geo     = new Geocoding(LocationProviderId::Mapbox, $cfg);
$iso     = new Isochrone(LocationProviderId::Mapbox, $cfg);
```

## Configuration

| Field | Type | Required | Notes |
|---|---|---|---|
| `accessToken` | `string` | yes | Mapbox public or secret access token (must include `directions:read`, `matrix:read`, `geocoding:read`, `isochrone:read` scopes) |

## Auth setup

Create a token at https://account.mapbox.com/access-tokens/. Sent as `access_token=` query param on every request. Static — no refresh.

## Vendor docs

- Directions: https://docs.mapbox.com/api/navigation/directions/
- Matrix: https://docs.mapbox.com/api/navigation/matrix/
- Geocoding v6: https://docs.mapbox.com/api/search/geocoding/
- Isochrone: https://docs.mapbox.com/api/navigation/isochrone/
- Rate limits: https://docs.mapbox.com/api/overview/#rate-limits

## Routing

### Endpoint

- Directions: `GET https://api.mapbox.com/directions/v5/mapbox/{profile}/{coordinates}`
- Optimized trips: `GET https://api.mapbox.com/optimized-trips/v1/mapbox/{profile}/{coordinates}`

### Narrowed input augmentations

Standard `RoutingOptions`. Travel mode is encoded into the URL path. Polyline returned in standard Google precision-5 format (re-encoded from Mapbox's polyline6 wire format).

### Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 | (any) | `AuthFailed` |
| 403 | (any) | `AuthFailed` |
| 422 | invalid coordinates | `InvalidRequest` |
| 429 | (respects `Retry-After`) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |

### Retry-After

On HTTP 429, `ConnectorError->cause['retryAfter']` carries the raw header; parsed seconds appear in `providerMessage`. No structured `retryAfterSeconds` field.

### `_passthrough` example

```php
use Thinwrap\Location\DTO\Passthrough;

$routing->route(new RoutingOptions(
    waypoints: $waypoints,
    passthrough: new Passthrough(query: ['annotations' => 'duration,distance,speed', 'overview' => 'full']),
));
```

## Matrix

### Endpoint

`GET https://api.mapbox.com/directions-matrix/v1/mapbox/{profile}/{coordinates}`

### Error mapping

Same as routing. Retry-After surfacing identical.

## Geocoding

### Endpoints

- Forward: `GET https://api.mapbox.com/search/geocode/v6/forward`
- Reverse: `GET https://api.mapbox.com/search/geocode/v6/reverse`
- Autocomplete (Searchbox): `GET https://api.mapbox.com/search/searchbox/v1/suggest`

### Narrowed input augmentations

`countryFilter` (ISO 3166-1 alpha-2) is translated to lowercased CSV `country=us,ca`. Other Geocoding/Searchbox-specific fields go via `_passthrough.query`.

## Isochrone

### Endpoint

`GET https://api.mapbox.com/isochrone/v1/mapbox/{profile}/{lng},{lat}`

### Narrowed input augmentations

Standard `IsochroneOptions`. `type: IsochroneType::Time | IsochroneType::Distance` toggles between `contours_minutes` and `contours_meters` query params. Mapbox accepts up to 4 contour values per call.
