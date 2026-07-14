---
providerId: osrm
operations:
  routing:
    auth:
      method: none
      tokenLifecycle: none
    endpoint:
      default: http://localhost:5000/route/v1
    versioning:
      vendorApiVersion: v1
      lastVerified: 2026-05-17
    selfHostable: true
    rateLimitDocsUrl: null
    retryAfterSurfaced: false
    notes_passthrough: |
      Coordinates are `lng,lat` (semicolon-separated). Travel mode is part of
      the profile name — `driving`, `walking`, `cycling` — and must match the
      profile compiled on the OSRM server. Optimization routes via the Trip
      service. Forward `annotations`, `overview`, `geometries`, `steps`,
      `alternatives` via `_passthrough.query`.
  matrix:
    auth:
      method: none
      tokenLifecycle: none
    endpoint:
      default: http://localhost:5000/table/v1
    versioning:
      vendorApiVersion: v1
      lastVerified: 2026-05-17
    selfHostable: true
    rateLimitDocsUrl: null
    retryAfterSurfaced: false
    notes_passthrough: |
      The connector forces `annotations=duration,distance` after the
      `_passthrough` merge — overriding via `_passthrough.query.annotations` is
      silently overwritten. To add extra annotations include both built-ins
      explicitly: `'duration,distance,nodes'`. OSRM Table may return HTTP 200
      with `code !== 'Ok'` (`NoTable`, `InvalidQuery`, `InvalidOptions`); the
      connector raises these as `ConnectorError` with
      `providerCode: ProviderCode::InvalidRequest`.
---

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

The connector pre-flight-validates `baseUrl` (must be `http(s)://…`, no trailing path) and throws `ConnectorError` with `providerCode: ProviderCode::InvalidRequest` before any HTTP call when malformed.

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
