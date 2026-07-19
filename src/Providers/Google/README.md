# Google Maps Platform Connectors (PHP)

Google Maps Platform connectors for routing, distance matrix, and geocoding via direct HTTP calls (no `google/apiclient` SDK).

## Quick install

See the [package README](../../../README.md) for installation. Dispatches when `LocationProviderId::Google` is passed to a facade.

```php
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Routing;
use Thinwrap\Location\Matrix;
use Thinwrap\Location\Geocoding;

$cfg = new GoogleConfig(apiKey: getenv('GOOGLE_KEY'));
$routing = new Routing(LocationProviderId::Google, $cfg);
$matrix  = new Matrix(LocationProviderId::Google,  $cfg);
$geo     = new Geocoding(LocationProviderId::Google, $cfg);
```

## Configuration

| Field | Type | Required | Notes |
|---|---|---|---|
| `apiKey` | `string` | yes | Google Maps Platform API key (single key works across Routes + Geocoding + Places) |
## Auth setup

Generate a key at https://console.cloud.google.com/google/maps-apis/credentials with the **Routes API**, **Geocoding API**, and **Places API** enabled. Sent as `X-Goog-Api-Key` header (Routes v2 / Matrix v2 / Places Autocomplete v1) or `key=` query param (Geocoding). Static key — no refresh, no rotation.

## Vendor docs

- Routes API v2: https://developers.google.com/maps/documentation/routes
- Geocoding API: https://developers.google.com/maps/documentation/geocoding
- Places Autocomplete: https://developers.google.com/maps/documentation/places/web-service/autocomplete
- Rate limits: https://developers.google.com/maps/documentation/routes/usage-and-billing

---

## Routing

### Endpoint

`POST https://routes.googleapis.com/directions/v2:computeRoutes`

### Narrowed input augmentations

The standard `RoutingOptions` shape applies as-is: `waypoints`, `travelMode`, `optimize`, `departureTime`, `avoidTolls`, `avoidFerries`, `avoidHighways`. Provider-specific Routes v2 features (lane guidance, route modifiers) go via `_passthrough.body`.

### Error mapping

| Vendor HTTP | Vendor signal | `ProviderCode` |
|---|---|---|
| 401 | (any) | `AuthFailed` |
| 403 | `error.status === 'QUOTA_EXCEEDED'` | `RateLimited` |
| 403 | (other) | `AuthFailed` |
| 400 | `error.details[]` `ErrorInfo.reason` (e.g. `API_KEY_INVALID`) | `AuthFailed` |
| 400 | (other) | `InvalidRequest` |
| 429 | (any; respects `Retry-After`) | `RateLimited` |
| 5xx | (any) | `ProviderUnavailable` |
| network failure | — | `ProviderUnavailable` |

### Retry-After

On HTTP 429, `ConnectorError->cause['retryAfter']` carries the raw `Retry-After` header value; the parsed seconds count appears in `ConnectorError->providerMessage` as `…; retry after N seconds`. There is **no** structured `retryAfterSeconds` field on `ConnectorError`.

### `_passthrough` example

```php
use Thinwrap\Location\DTO\Passthrough;

$routing->route(new RoutingOptions(
    waypoints: [$origin, $destination],
    passthrough: new Passthrough(
        body: ['languageCode' => 'fr', 'units' => 'IMPERIAL'],
        headers: ['X-Goog-FieldMask' => 'routes.legs.distanceMeters,routes.duration,routes.warnings'],
    ),
));
```

---

## Matrix

### Endpoint

`POST https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix`

### Narrowed input augmentations

Standard `MatrixOptions` (`origins`, `destinations`, `travelMode`, `departureTime`). The connector flattens the response into `MatrixCell[]` with `originIndex` + `destinationIndex`.

### Error mapping

Same table as routing (`Routes API` shares the error surface). Retry-After surfacing identical.

### `_passthrough` example

```php
$matrix->matrix(new MatrixOptions(
    origins: $origins,
    destinations: $destinations,
    passthrough: new Passthrough(body: ['routingPreference' => 'TRAFFIC_AWARE_OPTIMAL']),
));
```

---

## Geocoding

### Endpoint

- Forward / reverse: `GET https://maps.googleapis.com/maps/api/geocode/json`
- Autocomplete: `POST https://places.googleapis.com/v1/places:autocomplete`

### Narrowed input augmentations

Standard `GeocodeOptions` / `ReverseGeocodeOptions` / `AutocompleteOptions`. Provider-specific Places fields (`sessiontoken`, `radius`, `strictbounds`) go via `_passthrough.query`.

### Error mapping

Google returns HTTP 200 with a `status` field on geocoding errors. The connector maps:

| Google `status` | `ProviderCode` |
|---|---|
| `OK` / `ZERO_RESULTS` | (no error) |
| `REQUEST_DENIED` | `AuthFailed` |
| `OVER_QUERY_LIMIT` | `RateLimited` |
| `INVALID_REQUEST` | `InvalidRequest` |
| `UNKNOWN_ERROR` | `Unknown` |

### `_passthrough` example

```php
$geo->geocode(new GeocodeOptions(
    address: '1600 Amphitheatre Parkway',
    passthrough: new Passthrough(query: ['region' => 'us', 'language' => 'en', 'components' => 'country:US']),
));
```
