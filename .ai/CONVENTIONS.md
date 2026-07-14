# `thinwrap/location` (PHP) — Conventions

Naming, file layout, and test patterns for AI agents adding or refactoring a connector.
The frontmatter is a **single block at the very top of the file** (opening `---` on
line 1), with every operation keyed under `operations:`; the `# Title` follows the
closing `---`. GitHub only renders a `---…---` block as (hidden) frontmatter when it
leads the file — a block placed lower leaks its raw YAML into the page body. The
validator scans to the first `---`, so it will **not** catch a misplaced block; keep
it on top.

The per-connector README frontmatter schema authoritative source is
[`../schemas/connector-readme-schema.yaml`](../schemas/connector-readme-schema.yaml).

## Where files live in this repo

```
src/
  Routing.php                                 # facade (top-level, not under src/Facade)
  Matrix.php                                  # facade
  Geocoding.php                               # facade
  Isochrone.php                               # facade
  ConnectorError.php                          # unified error type
  Base/
    BaseConnector.php                         # HTTP + error wrapping
  Config/
    <Provider>Config.php                      # per-provider config DTO
  Connector/<Provider>/
    <Provider><Operation>Connector.php        # one connector class per operation
  Contract/
    <Operation>ConnectorInterface.php         # public contracts (4 total)
  DTO/
    LatLng.php                                # core coordinate
    Passthrough.php                           # _passthrough payload
    Routing/                                  # operation-specific DTOs
    Matrix/
    Geocoding/
    Isochrone/
  Enum/
    LocationProviderId.php                    # provider enum (6 cases)
    ProviderCode.php                          # 11-case error code enum
    TravelMode.php                            # 'driving'|'walking'|'cycling'
    IsochroneType.php                         # 'time'|'distance'
  Providers/<Provider>/
    README.md                                 # top-of-file YAML frontmatter (operations map) + body
    DTO/                                      # optional per-provider narrowed input types
    Enum/                                     # optional per-provider enums
  Util/
    Polyline.php                              # 4 public encode/decode methods
    Coordinate.php                            # join helpers
    IsochroneValidator.php
tests/
  Unit/Connector/<Provider>/                  # PHPUnit specs per connector
  Unit/Facade/                                # facade-dispatch specs
  Unit/DTO/                                   # DTO normalization specs
  Unit/Util/                                  # Polyline + Coordinate specs
  Static/                                     # PHPStan-only narrowing fixtures
schemas/
  connector-readme-schema.yaml                # per-connector README frontmatter schema
scripts/
  validate-frontmatter.php                    # no-deps validator, CI-wired
.ai/
  guidelines.md                               # contributor entry point + add-a-connector recipe
  ARCHITECTURE.md                             # 6 location-distinctive invariants + PHP rules
  CONVENTIONS.md                              # this file
.github/workflows/
  ci.yml                                      # PHP 8.2/8.3/8.4 matrix + tests + lint + frontmatter
  publish.yml                                 # publish to Packagist
```

## Provider-ID enum

Provider IDs are cases on the backed string enum `Thinwrap\Location\Enum\LocationProviderId`.
Adding a connector means adding a case + a `<Provider>Config` DTO + N connector classes +
extending the facade `match` block.

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

## File naming

| File | Required? | Purpose |
|---|---|---|
| `src/Connector/<Provider>/<Provider><Operation>Connector.php` | yes | Connector class extending `BaseConnector` |
| `tests/Unit/Connector/<Provider>/<Provider><Operation>ConnectorTest.php` | yes | PHPUnit spec |
| `src/Config/<Provider>Config.php` | yes | Exported `<Provider>Config` DTO (shared across ops) |
| `src/Providers/<Provider>/DTO/<Provider><Op>Options.php` | optional | Per-provider narrowed input type (for augmentations beyond baseline) |
| `src/Providers/<Provider>/Enum/<Provider><Concept>.php` | optional | Per-provider narrowed enum (e.g. `HereTransportMode`) |
| `src/Providers/<Provider>/README.md` | yes | Top-of-file YAML frontmatter (operations map) + body |

## `mapVendorError(int $status, mixed $body): ProviderCode` pattern

Each connector implements a private `mapVendorError(int $httpStatus, mixed $body): ProviderCode`.
Canonical baseline mapping (override per vendor when the response carries finer signal):

| HTTP status | Default `ProviderCode` |
|---|---|
| 400 | `InvalidRequest` |
| 401 | `AuthFailed` |
| 403 | `AuthFailed` (or `RateLimited` if vendor signals quota) |
| 404 | `InvalidRequest` |
| 422 | `InvalidRequest` |
| 429 | `RateLimited` |
| 5xx | `ProviderUnavailable` |
| network failure (PSR-18 `ClientExceptionInterface`) | `ProviderUnavailable` |
| unparseable | `Unknown` |

Plus the 5 location-extended cases raised by **pre-flight validation** (OSRM mostly) and
**wire-translation** (HERE Matrix polling timeout, TomTom unsupported travel mode).

## `Retry-After` surfacing pattern

The wrapper does NOT carry a structured `retryAfterSeconds` field on `ConnectorError`.
When the vendor sets `Retry-After`, the connector:

```php
$rawBody = (string) $response->getBody();
$errorBody = json_decode($rawBody, true) ?? null;
$retryAfter = $response->getHeaderLine('Retry-After'); // empty string when absent

$cause = is_array($errorBody) ? $errorBody : [];
if ($retryAfter !== '') {
    $cause['retryAfter'] = $retryAfter;
}

$parsedSeconds = $retryAfter !== '' ? (int) $retryAfter : null;
$baseMessage = $this->readVendorErrorMessage($errorBody);
$providerMessage = $parsedSeconds !== null
    ? ($baseMessage !== null ? "$baseMessage; retry after $parsedSeconds seconds" : "retry after $parsedSeconds seconds")
    : $baseMessage;

throw new ConnectorError(
    statusCode: $response->getStatusCode(),
    providerCode: ProviderCode::RateLimited,
    providerMessage: $providerMessage,
    cause: $cause,
);
```

Spec tests assert:
- `$e->cause['retryAfter'] === '<raw header string>'`
- `$e->providerMessage` contains `'N seconds'` for parseable headers.
- **Do NOT** assert `$e->retryAfterSeconds` — that field does not exist.

## Test pattern (PHPUnit 11)

Mock `Psr\Http\Client\ClientInterface` with `GuzzleHttp\Psr7\Response` fixtures. Inject
the mock through the `client` parameter on the `*Config` constructor or via Reflection
when the connector caches the discovered client. No real HTTP.

```php
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Thinwrap\Location\Config\GoogleConfig;
use Thinwrap\Location\Connector\Google\GoogleRoutingConnector;
use Thinwrap\Location\DTO\LatLng;
use Thinwrap\Location\DTO\Routing\RoutingOptions;

final class GoogleRoutingConnectorTest extends TestCase
{
    #[Test]
    public function it_POSTs_to_the_routes_v2_endpoint(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('sendRequest')
            ->with($this->callback(fn(RequestInterface $r) =>
                $r->getMethod() === 'POST'
                && (string) $r->getUri() === 'https://routes.googleapis.com/directions/v2:computeRoutes'
            ))
            ->willReturn(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'routes' => [['legs' => [], 'distanceMeters' => 100, 'duration' => '60s', 'polyline' => ['encodedPolyline' => '_a']]],
            ], JSON_THROW_ON_ERROR)));

        $cfg = new GoogleConfig(apiKey: 'k', client: $client);
        (new GoogleRoutingConnector($cfg))->route(new RoutingOptions(
            waypoints: [new LatLng(40.7, -74), new LatLng(41.4, -73)],
        ));
    }
}
```

### Optional fixture extraction

Specs may inline their request/response fixtures (the v1.0 default) or extract them to
`tests/Fixtures/<Provider>/<Operation>/<Scenario>.{request,response}.json`. Extraction
is encouraged for fixtures shared across multiple specs but never forced. The brownfield
specs use the inline pattern.

## `ProviderCode` enum case mapping

PHP-side error mapping uses the `ProviderCode` enum (named cases), but the **backing
string value** must match the TS string literal exactly for cross-language parity
(this is a cross-language parity requirement):

| PHP enum case | Backing string (= TS literal) |
|---|---|
| `ProviderCode::RateLimited` | `'rate_limited'` |
| `ProviderCode::AuthFailed` | `'auth_failed'` |
| `ProviderCode::InvalidRequest` | `'invalid_request'` |
| `ProviderCode::InvalidRecipient` | `'invalid_recipient'` |
| `ProviderCode::ProviderUnavailable` | `'provider_unavailable'` |
| `ProviderCode::Unknown` | `'unknown'` |
| `ProviderCode::UnsupportedField` | `'unsupported_field'` |
| `ProviderCode::UnsupportedOption` | `'unsupported_option'` |
| `ProviderCode::UnsupportedTravelMode` | `'unsupported_travel_mode'` |
| `ProviderCode::ProfileNotConfigured` | `'profile_not_configured'` |
| `ProviderCode::MatrixPollingTimeout` | `'matrix_polling_timeout'` |

## `Passthrough` merge + augmentation pattern

Inside each connector, after building the vendor body / headers / query:

```php
use Thinwrap\Location\DTO\Passthrough;

$merged = $this->applyPassthrough($body, $headers, $query, $options->passthrough);
$response = $this->sendPostJson($url, $merged['body'], $merged['headers']);
```

- Body merges **deep**; headers + query merge **shallow**.
- Consumer values win on conflict (last-write-wins).
- `Passthrough` keys are forwarded verbatim — no casing transformation.

Operation-specific augmentations follow the per-provider narrowed-type pattern; v1.0 has
no augmentations on most providers (HERE has `HereRoutingOptions` as a worked example).
Future augmentations add per-provider narrowed types via `src/Providers/<Provider>/DTO/`.

## PHP / lint / build

- `composer.json` requires `php: ^8.2`. `declare(strict_types=1)` on every file.
- PHPStan level 8 — zero errors. Run: `composer phpstan`.
- PER-CS via PHP-CS-Fixer. Run: `composer cs` (check) or `composer cs-fix`.
- PHPUnit 11 with `#[Test]` attributes. Run: `composer test`.
- `composer validate-frontmatter` validates every `src/Providers/<Provider>/README.md`
  against `schemas/connector-readme-schema.yaml`.
- No build step (no transpilation). Composer auto-loads via PSR-4 from `src/`.
