# `thinwrap/location` (PHP) — Architecture

One-page summary of the facade-dispatch-base pattern and the 6 location-distinctive
invariants plus the PHP-specific architecture rules.

## Why facade + dispatch + base

Three layers. Consumer constructs an operation facade by `LocationProviderId`; the facade
dispatches to a specific connector class; the connector extends `BaseConnector`, which
centralizes HTTP + JSON parsing + error mapping. No global middleware.

```
Consumer code
    │  new Routing(LocationProviderId::Google, $cfg)
    ▼
Routing facade ─── lookup by enum case ───► GoogleRoutingConnector
    │  ->route($input)                              │  extends BaseConnector
    ▼                                               ▼
$connector->route($input)                      BaseConnector::sendPostJson($url, $body, $opts)
                                                    │
                                                    ▼  PSR-18 ClientInterface (BYO via $cfg->client)
                                               Vendor API
```

## `LocationProviderId`-based dispatch (PHP-specific)

Provider routing in PHP uses the backed enum `Thinwrap\Location\Enum\LocationProviderId`:

```php
enum LocationProviderId: string
{
    case Google = 'google';
    case Mapbox = 'mapbox';
    case Here   = 'here';
    case Esri   = 'esri';
    case Osrm   = 'osrm';
    case TomTom = 'tomtom';
}
```

Facades accept this enum + a provider-specific `*Config` DTO. The facade `match`-dispatches
to the concrete `<Provider><Operation>Connector` class. Adding a provider means adding a
new enum case + a new `*Config` + N connector classes + extending the facade `match`.

## PSR-18 / PSR-17 / `php-http/discovery` wiring

Runtime HTTP plumbing is PSR-only:

- **PSR-18 `Psr\Http\Client\ClientInterface`** — every `*Config` DTO accepts an optional
  `?ClientInterface $client`. When omitted, `BaseConnector` calls
  `Http\Discovery\Psr18ClientDiscovery::find()`.
- **PSR-17 `RequestFactoryInterface` + `StreamFactoryInterface`** — same auto-discovery
  pattern via `Http\Discovery\Psr17FactoryDiscovery::findRequestFactory()` /
  `findStreamFactory()`.
- **No vendor SDK in `require`.** Only `psr/http-client`, `psr/http-factory`,
  `php-http/discovery`. Consumers bring their own client (`guzzlehttp/guzzle`,
  `symfony/http-client`, `kriswallsmith/buzz`, etc.).

## PHPStan level 8 + union-typed config narrowing

The codebase passes PHPStan level 8 with zero errors. Facades use `assert()` to narrow
union-typed config parameters before dispatching:

```php
public function route(RoutingOptions $options): RoutingResult
{
    $cfg = $this->config;
    assert($cfg instanceof GoogleConfig); // narrows for PHPStan
    return new GoogleRoutingConnector($cfg)->route($options);
}
```

Consumer code that uses union-typed config narrowing (e.g. `GoogleConfig | MapboxConfig`)
should run PHPStan level 8 to catch the same class of errors.

## 6 Location-Distinctive Invariants

These are the six rules that distinguish location scope from notifications scope. They
are referenced by every per-connector README and enforced by the test suite.

### 1. `providerId`-only instance shape

Facades and connectors expose `providerId` only — no `id` + `channelType` two-tuple
(notifications scope's pattern). Operation is conveyed by the facade class itself.

### 2. NO casing-transform layer

Explicit divergence from notifications. Each connector formats request bodies in the
vendor's native casing inline; `Passthrough` keys are forwarded verbatim. There is no
casing-transform helper, no `Casing::Snake` / `Casing::Camel` / `Casing::Pascal` modes.

### 3. Polyline encoding contract

All facades emit Google precision-5 encoded polyline on `$result->polyline`. Four public
static methods on `Thinwrap\Location\Util\Polyline` expose the encode/decode primitives:

| Method | Purpose |
|---|---|
| `Polyline::encodePolyline(array $latLngs): string` | Google precision-5 encode |
| `Polyline::decodePolyline(string $s): array` | Google precision-5 decode |
| `Polyline::decodeFlexPolyline(string $s): array` | HERE flex-polyline decode (HERE re-encodes internally) |
| `Polyline::encodeEsriPaths(array $paths): string` | ESRI coordinate-array → precision-5 (ESRI re-encodes internally) |

The public utility surface is fixed at four methods for v1.0. Adding a fifth method requires a new minor.

### 4. OSRM self-host invariants

OSRM is the only connector requiring an explicit `baseUrl` and shipping zero auth. The
`OsrmRoutingConnector` / `OsrmMatrixConnector` constructors pre-flight-validate
`baseUrl` (`http(s)://`, no trailing path) and throw typed `ConnectorError` with
`providerCode: ProviderCode::InvalidRequest` before any HTTP call. The Table service
forces `annotations=duration,distance` post-`Passthrough`-merge to guarantee both fields
on every cell.

### 5. Normalization invariants

| Field | Unit |
|---|---|
| Distance | **meters** (`float`) |
| Duration | **seconds** (`float`) |
| Coordinates | `LatLng = { lat: float, lng: float }` (lat-first) |
| Polyline | Google precision-5 string |

Connectors that receive vendor data in km / miles / minutes (ESRI most prominently)
convert at the wire layer before populating the result DTO.

### 6. Location-extended `ProviderCode` enum

11 cases: 6 notifications-canonical + 5 location-extended.

- Canonical: `RateLimited`, `AuthFailed`, `InvalidRequest`, `InvalidRecipient`,
  `ProviderUnavailable`, `Unknown`.
- Location-extended: `UnsupportedField` (e.g. OSRM rejecting `departureTime`),
  `UnsupportedOption` (e.g. OSRM rejecting `avoidTolls`),
  `UnsupportedTravelMode` (e.g. ESRI/TomTom Matrix rejecting cycling),
  `ProfileNotConfigured` (OSRM missing compiled travel-mode profile),
  `MatrixPollingTimeout` (HERE/TomTom Matrix exceeded 60s deadline).

## Per-connector locality

`mapVendorError(int $status, mixed $body): ProviderCode` is a private per-connector
method. Each connector ships its own canonical HTTP-status → `ProviderCode` mapping
table (see `CONVENTIONS.md`). Outlier translations (e.g. HERE Matrix v8 submit/poll/
retrieve, TomTom Reachable Range single-budget fan-out, ESRI HTTP-200-with-error-body
inspection) live inside the corresponding connector — never in `BaseConnector`, never
as global middleware.

## Stateless wrapper, no retry

The wrapper holds no token cache, no connection
pool, no retry buffer. The HERE Matrix poll loop is a per-request transient — it does
not survive the `->matrix()` call. No `tokenCache` hook is needed in v1.0 because every
location auth method is static or refreshable by the consumer (ArcGIS); there is no
short-lived signed-token operation in scope.

There is **no** `retryAfterSeconds` field on `ConnectorError`. Retry-After surfaces via:
- `$e->cause['retryAfter']` — raw header string preserved on the error's `cause` payload.
- `$e->providerMessage` — parsed seconds woven into the human-readable text as
  `…; retry after N seconds`.

## Cross-reference

- Naming, file layout, test patterns: [`./CONVENTIONS.md`](./CONVENTIONS.md)
- Adding a connector / contributor entry point: [`./guidelines.md`](./guidelines.md)
- Consumer usage (install, calling the facades, error handling): [`../README.md`](../README.md)
