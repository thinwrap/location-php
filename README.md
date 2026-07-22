# thinwrap/location

Unified PHP facade for 21 location connectors across routing, matrix, geocoding, and isochrone — over 6 providers (Google, Mapbox, HERE, ESRI, TomTom, OSRM). Stateless. Zero vendor SDKs. Bring your own PSR-18 HTTP client.

## Install

```bash
composer require thinwrap/location
```

Requires PHP ≥8.2. PSR-18 HTTP client + PSR-17 factories are auto-discovered via
`php-http/discovery` — if you don't already have one installed:

```bash
composer require guzzlehttp/guzzle guzzlehttp/psr7
```

## End-to-end example — 2-minute time-to-first-route

```php
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Routing\RoutingOptions;
use Thinwrap\Location\Routing;
use Thinwrap\Location\ConnectorError;

$routing = new Routing(LocationProviderId::Google, new GoogleConfig(apiKey: getenv('GOOGLE_KEY')));

try {
    $result = $routing->route(new RoutingOptions(
        waypoints: [
            new LatLng(40.7128, -74.0060),  // New York
            new LatLng(41.4173, -73.0001),  // Bridgeport
        ],
        travelMode: 'driving',
    ));
    echo $result->totalDistanceMeters;   // distance in meters
    echo $result->totalDurationSeconds;  // duration in seconds
    echo $result->polyline;              // Google precision-5 polyline string
} catch (ConnectorError $e) {
    error_log($e->providerCode->value . ': ' . ($e->providerMessage ?? ''));
}
```

## Switching providers

Change the `LocationProviderId` case and config DTO; the input and output shape stay identical.

```php
use Thinwrap\Location\Config\MapboxConfig;

$a = new Routing(LocationProviderId::Google, new GoogleConfig(apiKey: getenv('GOOGLE_KEY')));
$b = new Routing(LocationProviderId::Mapbox, new MapboxConfig(accessToken: getenv('MAPBOX_TOKEN')));

$sameInput = new RoutingOptions(
    waypoints: [$origin, $destination],
    travelMode: 'driving',
);
$ra = $a->route($sameInput);
$rb = $b->route($sameInput);
// $ra and $rb share the same RoutingResult shape:
//   { legs, totalDistanceMeters, totalDurationSeconds, polyline, waypointOrder?, raw }
```

## Bring your own PSR-18 client

Inject any PSR-18 client through the third constructor argument on the facade — useful for
tracing, retries, mocking, or proxying through `symfony/http-client`. The `*Config` DTO
carries only credentials; the HTTP client is a facade-level seam.

```php
use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$tracingClient = new class(new Client()) implements ClientInterface {
    public function __construct(private Client $inner) {}
    public function sendRequest(RequestInterface $req): ResponseInterface
    {
        error_log('→ ' . $req->getMethod() . ' ' . (string) $req->getUri());
        return $this->inner->sendRequest($req);
    }
};

$routing = new Routing(
    LocationProviderId::Google,
    new GoogleConfig(apiKey: getenv('GOOGLE_KEY')),
    $tracingClient,
);
```

The wrapper holds no state — no token cache, no connection pool, no retry buffer. Every
operation is a single function call from input to output with one HTTP round-trip (except
HERE Matrix v8, which transparently runs a submit → poll → retrieve cycle behind a single
`$matrix->matrix($input)` call).

## Error handling

Every failure surfaces as `ConnectorError` with a typed `ProviderCode`. Compose your own
retry strategy from `$e->providerCode` and `$e->cause` (which carries the raw
`Retry-After` header where the vendor sets one).

```php
use Thinwrap\Location\ConnectorError;
use Thinwrap\Location\Enum\ProviderCode;

try {
    $routing->route($input);
} catch (ConnectorError $e) {
    match ($e->providerCode) {
        ProviderCode::RateLimited           => /* respect Retry-After in $e->cause      */ null,
        ProviderCode::AuthFailed            => /* rotate credentials                     */ null,
        ProviderCode::InvalidRequest        => /* fix payload                            */ null,
        ProviderCode::InvalidRecipient      => /* fix destination                        */ null,
        ProviderCode::ProviderUnavailable   => /* transient 5xx — your retry strategy    */ null,
        ProviderCode::UnsupportedField      => /* drop OSRM-incompatible field           */ null,
        ProviderCode::UnsupportedOption     => /* drop OSRM-incompatible option          */ null,
        ProviderCode::UnsupportedTravelMode => /* fall back to a supported travel mode   */ null,
        ProviderCode::ProfileNotConfigured  => /* compile the OSRM profile               */ null,
        ProviderCode::MatrixPollingTimeout  => /* resume via $e->cause['matrixId']       */ null,
        ProviderCode::Unknown               => /* fallback                               */ null,
    };
}
```

The wrapper performs no automatic retry. The `Retry-After` header (when present on
HTTP 429) is surfaced via `$e->cause['retryAfter']` (raw header string) and the parsed
seconds count is woven into `$e->providerMessage` (`…; retry after N seconds`). There is
**no** structured `retryAfterSeconds` field on `ConnectorError`.

`$e->providerMessage` is safe to log — known credential query params are redacted from
transport-error messages. But `$e->cause` and `$e->getPrevious()` retain the raw underlying
HTTP-client exception, which may embed the full request URL and headers (including live
credentials); do not log them unfiltered.

## `_passthrough` escape valve

When the normalized input doesn't expose a vendor-specific field, forward arbitrary keys
via the `Passthrough` DTO on the operation options. The wrapper deep-merges `body`,
shallow-merges `headers` and `query`. Consumer values win on conflict. Keys are forwarded
verbatim — no casing transformation.

```php
use Thinwrap\Location\DTO\Passthrough;

$routing->route(new RoutingOptions(
    waypoints: [$origin, $destination],
    passthrough: new Passthrough(
        body:    ['languageCode' => 'fr', 'units' => 'IMPERIAL'],
        headers: ['X-Goog-FieldMask' => 'routes.legs.distanceMeters,routes.duration'],
        query:   ['region' => 'us'],
    ),
));
```

Each per-connector README documents its vendor-specific `_passthrough` examples.

## Polyline utilities

```php
use Thinwrap\Location\Util\Polyline;

$latLngs = Polyline::decodePolyline($result->polyline);             // list<LatLng>
$re      = Polyline::encodePolyline($latLngs);                      // back to precision-5
$here    = Polyline::decodeFlexPolyline('BFoz5...');                // HERE flex-polyline
$esri    = Polyline::encodeEsriPaths([[[-74, 40], [-73.5, 40.5]]]); // ESRI paths
```

All facades emit Google precision-5 encoded polyline on `$result->polyline`. The four
public static methods on `Polyline` are the only encode/decode primitives exported —
locked at v1.0.

## Language constraints

- PHP 8.2 minimum; PHPStan level 8 expected for consumer code that uses union-typed config narrowing.
- Runs on PHP 8.2, 8.3, and 8.4 (CI matrix; Linux only at v1.0 — Windows / macOS deferred to v1.1).
- `declare(strict_types=1)` is required on every file in this library and recommended for consumer code.
- Only three runtime dependencies — `psr/http-client` + `psr/http-factory` (interfaces) and `php-http/discovery`, which auto-wires a PSR-18 client when none is injected. No vendor SDKs.
- Server-only. Most providers require server-only secrets — there is no browser story.

## Public API surface (locked at v1.0)

| Category | Exports |
|---|---|
| Facades | `Routing`, `Matrix`, `Geocoding`, `Isochrone` (top-level under `Thinwrap\Location\`) |
| Error | `ConnectorError`, `Thinwrap\Location\Enum\ProviderCode` |
| Geometry | `Thinwrap\Location\DTO\LatLng`, `Thinwrap\Location\Util\Polyline` (4 static methods: `encodePolyline`, `decodePolyline`, `decodeFlexPolyline`, `encodeEsriPaths`) |
| Routing connectors | `GoogleRoutingConnector`, `MapboxRoutingConnector`, `HereRoutingConnector`, `EsriRoutingConnector`, `TomTomRoutingConnector`, `OsrmRoutingConnector` |
| Matrix connectors | `GoogleMatrixConnector`, `MapboxMatrixConnector`, `HereMatrixConnector`, `EsriMatrixConnector`, `TomTomMatrixConnector`, `OsrmMatrixConnector` |
| Geocoding connectors | `GoogleGeocodingConnector`, `MapboxGeocodingConnector`, `HereGeocodingConnector`, `EsriGeocodingConnector`, `TomTomGeocodingConnector` |
| Isochrone connectors | `MapboxIsochroneConnector`, `HereIsochroneConnector`, `EsriIsochroneConnector`, `TomTomIsochroneConnector` |
| Config DTOs | `GoogleConfig`, `MapboxConfig`, `HereConfig`, `EsriConfig`, `TomTomConfig`, `OsrmConfig` |
| Enums | `LocationProviderId`, `ProviderCode`, `TravelMode`, `IsochroneType` |

## Per-connector documentation

Each per-connector README documents auth, endpoints (regional/sandbox), narrowed input
augmentations, outlier translations, error-code mappings, and `_passthrough` examples.

### Routing (6)

| Provider | README |
|---|---|
| `google` | [src/Providers/Google/README.md](src/Providers/Google/README.md) |
| `mapbox` | [src/Providers/Mapbox/README.md](src/Providers/Mapbox/README.md) |
| `here`   | [src/Providers/Here/README.md](src/Providers/Here/README.md) |
| `esri`   | [src/Providers/Esri/README.md](src/Providers/Esri/README.md) |
| `tomtom` | [src/Providers/TomTom/README.md](src/Providers/TomTom/README.md) |
| `osrm`   | [src/Providers/Osrm/README.md](src/Providers/Osrm/README.md) |

### Matrix (6)

| Provider | README |
|---|---|
| `google` | [src/Providers/Google/README.md](src/Providers/Google/README.md) |
| `mapbox` | [src/Providers/Mapbox/README.md](src/Providers/Mapbox/README.md) |
| `here`   | [src/Providers/Here/README.md](src/Providers/Here/README.md) |
| `esri`   | [src/Providers/Esri/README.md](src/Providers/Esri/README.md) |
| `tomtom` | [src/Providers/TomTom/README.md](src/Providers/TomTom/README.md) |
| `osrm`   | [src/Providers/Osrm/README.md](src/Providers/Osrm/README.md) |

### Geocoding (5)

| Provider | README |
|---|---|
| `google` | [src/Providers/Google/README.md](src/Providers/Google/README.md) |
| `mapbox` | [src/Providers/Mapbox/README.md](src/Providers/Mapbox/README.md) |
| `here`   | [src/Providers/Here/README.md](src/Providers/Here/README.md) |
| `esri`   | [src/Providers/Esri/README.md](src/Providers/Esri/README.md) |
| `tomtom` | [src/Providers/TomTom/README.md](src/Providers/TomTom/README.md) |

### Isochrone (4)

| Provider | README |
|---|---|
| `mapbox` | [src/Providers/Mapbox/README.md](src/Providers/Mapbox/README.md) |
| `here`   | [src/Providers/Here/README.md](src/Providers/Here/README.md) |
| `esri`   | [src/Providers/Esri/README.md](src/Providers/Esri/README.md) |
| `tomtom` | [src/Providers/TomTom/README.md](src/Providers/TomTom/README.md) |

## Baseline-coverage discipline

The unified facade surface includes only features ≥90% of providers natively support.
Sub-baseline fields are accessible via the `Passthrough` escape hatch, plus the one
per-provider narrowed type that exists at v1.0 (HERE routing, `src/Providers/Here/DTO/`).

## Migrating

### From `googlemaps/google-maps-services-php`

```php
// Before — googlemaps/google-maps-services-php
$client = new \GoogleMaps\Client(['key' => 'YOUR_KEY']);
$response = $client->directions([...]);

// After
use Thinwrap\Location\Enum\LocationProviderId;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Routing;

$routing = new Routing(LocationProviderId::Google, new GoogleConfig(apiKey: 'YOUR_KEY'));
$result = $routing->route(new RoutingOptions(waypoints: [$origin, $destination]));
```

### From `mapbox/mapbox-sdk-php` (community port)

```php
// Before — community Mapbox SDK
$mapbox = new \Mapbox\Mapbox(['access_token' => 'YOUR_TOKEN']);
$directions = $mapbox->directions([...]);

// After
use Thinwrap\Location\Config\MapboxConfig;

$routing = new Routing(LocationProviderId::Mapbox, new MapboxConfig(accessToken: 'YOUR_TOKEN'));
$result = $routing->route(new RoutingOptions(waypoints: [$origin, $destination]));
```

### From raw HTTP / Guzzle

If you've been hand-rolling vendor HTTP calls with Guzzle, the facade collapses the
boilerplate to one line per call. Error handling and retry composition stay yours.

## For AI agents and contributors

- [`.ai/guidelines.md`](.ai/guidelines.md) — contributor entry point: how to add a connector.
- [`.ai/ARCHITECTURE.md`](.ai/ARCHITECTURE.md) — 6 location-distinctive invariants + PHP rules.
- [`.ai/CONVENTIONS.md`](.ai/CONVENTIONS.md) — naming, file layout, test patterns.

## License

MIT.
