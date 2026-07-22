# `thinwrap/location` (PHP) — contributor guide

This folder (`.ai/`) is for developers — and the coding agents working alongside them — who are
**changing this library**: adding a connector or operation, or improving the package. It is not
usage documentation.

> **Using the package in your app?** See [`../README.md`](../README.md) and the per-connector
> READMEs under [`../src/Providers/`](../src/Providers). `.ai/` is not part of the Packagist dist —
> its only audience is people working in the repo.

## Map of this folder

- **guidelines.md** (this file) — entry point + the "add a connector" recipe.
- [`ARCHITECTURE.md`](./ARCHITECTURE.md) — the facade → dispatch → base model and the location-distinctive + PHP/PSR invariants every change must hold.
- [`CONVENTIONS.md`](./CONVENTIONS.md) — naming, file/namespace layout, error mapping, `_passthrough`, the per-connector README convention.

## The shape in one sentence

A consumer constructs an operation facade by `LocationProviderId`
(`new Routing(LocationProviderId::Google, $config)`); the facade dispatches to a per-operation
connector under `src/Connector/<Provider>/` that extends `BaseConnector` (in `src/Base/`), which
centralizes the PSR-18 request + JSON parsing + error mapping + result normalization. No global
middleware — vendor specifics stay local to the connector.

## Setup & verify

```bash
composer install
composer test && composer phpstan && composer cs
```

PHP ≥8.2, `declare(strict_types=1)` on every file. **No vendor SDKs** — `php-http/discovery`
is the only runtime dependency (it auto-wires a PSR-18 client when the consumer injects none);
otherwise consumers bring their own PSR-18 client; SigV4 for Esri is hand-rolled.

## Add a connector

One operation = one connector class per provider. Copy [`src/Connector/Google/`](../src/Connector/Google)
as your template (it implements routing + matrix + geocoding). Namespace is
`Thinwrap\Location\Connector\<Provider>`. Touch-points, in order:

1. **Register the id** — add the case to [`src/Enum/LocationProviderId.php`](../src/Enum/LocationProviderId.php).
2. **Config** — `src/Config/<Provider>Config.php` (`final readonly class`).
3. **Connectors** — `src/Connector/<Provider>/<Provider><Operation>Connector.php`, one per supported operation (`Routing`/`Matrix`/`Geocoding`/`Isochrone`); `extends BaseConnector`, implements the operation interface from [`src/Contract/`](../src/Contract), private `mapVendorError(...)`.
4. **README + narrowed types** — `src/Providers/<Provider>/README.md` (plain Markdown that opens directly with its `# Title` — no YAML metadata block) + optional `DTO/` / `Enum/` for narrowed input. **This** is the connector's consumer doc; keep it complete and at parity with the sibling-language libraries.
5. **Dispatch** — add the `match` arm to each relevant facade (`src/Routing.php`, `src/Matrix.php`, `src/Geocoding.php`, `src/Isochrone.php`).
6. **Test** — `tests/Unit/Connector/<Provider>/<Provider><Operation>ConnectorTest.php`; PHPUnit with `#[Test]`, mock the PSR-18 client.

### Definition of done (the CI gates)

```bash
composer test                 # PHPUnit; ≥80% line-coverage gate
composer phpstan              # PHPStan level 8, zero errors
composer cs                   # PHP-CS-Fixer (PER-CS); composer cs-fix to apply
```

CI runs these on PHP 8.2 / 8.3 / 8.4, plus an offline import smoke (zero construct-time egress),
license + no-vendor-SDK gates.

## Invariants you must not break

Full reasoning lives in [`ARCHITECTURE.md`](./ARCHITECTURE.md); the short list:

- **No vendor SDKs.** `php-http/discovery` is the only runtime dependency (auto-wires a PSR-18 client when none is injected).
- **Stateless wrapper.** No caching, retries, idempotency keys, or telemetry. (HERE Matrix submit/poll/retrieve is transient, inside a single call.)
- **≥90% baseline-coverage rule.** A field belongs on the base operation input only if ≥90% of that operation's providers support it; everything else goes to `_passthrough` (input) / `raw` (output) or a narrowed type.
- **Normalize at the wire layer.** Distance → meters, duration → seconds, coordinates → `{ lat, lng }`, geometry → Google precision-5 polyline. The four `Polyline` methods are locked at v1.0, cross-language-parity with the TS lib.
- **Per-connector locality.** `mapVendorError` and outlier translations live inside `src/Connector/<Provider>/` — never in `BaseConnector`. No casing-transform layer; keys forwarded verbatim.
- **`ProviderCode`**: 6 canonical + 5 location-extended values, byte-identical to the TS lib, surfaced via `ConnectorError`; the raw `Retry-After` rides in `cause` (no top-level `retryAfterSeconds`).
- **OSRM** requires an explicit `baseUrl` and validates it pre-flight.
