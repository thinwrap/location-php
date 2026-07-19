# OSRM Connectors (PHP)

[OSRM](https://project-osrm.org/) (Open Source Routing Machine) connectors for routing and distance matrix. **Self-hosted** — no API key, no managed service.

## Quick install

See the [package README](../../../README.md) for installation. Dispatches when `LocationProviderId::Osrm` is passed to a facade.

```php
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Config\OsrmConfig;
use Thinwrap\Location\Routing;
use Thinwrap\Location\Matrix;

$cfg = new OsrmConfig(baseUrl: 'http://localhost:5000');
$routing = new Routing(LocationProviderId::Osrm, $cfg);
$matrix  = new Matrix(LocationProviderId::Osrm,  $cfg);
```

## Configuration

| Field | Type | Required | Notes |
|---|---|---|---|
| `baseUrl` | `string` | yes | OSRM server URL (e.g. `http://localhost:5000` or your hosted instance) |

The connector requires an explicit non-empty `baseUrl` and throws `ConnectorError` with `providerCode: ProviderCode::InvalidRequest` before any HTTP call when it's missing.

## Auth setup

**None.** OSRM is self-hosted. Front it with a reverse proxy if you need authentication or rate limiting — 401/429 responses from the proxy are surfaced as `ProviderCode::AuthFailed` / `ProviderCode::RateLimited`.

## Vendor docs

- OSRM Route service: https://project-osrm.org/docs/v5.24.0/api/#route-service
- OSRM Trip service: https://project-osrm.org/docs/v5.24.0/api/#trip-service
- OSRM Table service: https://project-osrm.org/docs/v5.24.0/api/#table-service

## Routing

### Endpoints

- Routing: `GET {baseUrl}/route/v1/{profile}/{coordinates}`
- Optimization (TSP): `GET {baseUrl}/trip/v1/{profile}/{coordinates}`

### Narrowed input augmentations

Pre-flight validation raises typed errors before any HTTP call:

| Unsupported field | `ProviderCode` |
|---|---|
| `departureTime` (no live-traffic on stock OSRM) | `UnsupportedField` |
| `avoidTolls` | `UnsupportedOption` |
| `avoidFerries` | `UnsupportedOption` |
| `avoidHighways` | `UnsupportedOption` |

If the requested `travelMode` doesn't have a compiled profile on the server, OSRM returns HTTP 400 with a profile-missing body which the connector maps to `providerCode: ProviderCode::ProfileNotConfigured`.

### Retry-After

**Not surfaced.** OSRM has no documented rate-limit; any 429 surfaces from your reverse-proxy layer, and `Retry-After` (if set by the proxy) is forwarded as `cause['retryAfter']` in best-effort mode.

## Matrix

### Endpoint

`GET {baseUrl}/table/v1/{profile}/{coordinates}?annotations=duration,distance&sources={…}&destinations={…}`

### Narrowed input augmentations

Pre-flight validation (Routing's table applies, except `avoidFerries` / `avoidHighways` don't exist on `MatrixOptions`):

| Unsupported field | `ProviderCode` |
|---|---|
| `departureTime` | `UnsupportedField` |
| `avoidTolls` | `UnsupportedOption` |

The connector flattens OSRM's 2D arrays to `MatrixCell[]`.

### Retry-After

**Not surfaced** (same rationale as Routing).
