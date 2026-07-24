# Changelog

All notable changes to `thinwrap/location` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] — 2026-07-24

### Added

- **ESRI walking travel mode.** `TravelMode::Walking` now selects ESRI's
  pedestrian network across routing, matrix, and isochrone by sending the full
  ArcGIS "Walking Time" travel-mode object — a bare `"Walking"` string is
  ignored by the service, which stays on the default driving impedance. The
  mode-dependent impedance column (`WalkTime`) is read back correctly.
  `TravelMode::Cycling` raises `unsupported_travel_mode`; ArcGIS's World
  network services do not provide a cycling mode.

### Changed

- **HERE Matrix (v8)** requests `Accept-Encoding: gzip` (HERE returns 406
  without it) and defensively `gzdecode()`s the matrix payload only when the
  bytes are actually gzip-compressed, so it works whether or not the PSR-18
  client already inflated the response.

### Fixed

- **ESRI OD cost matrix** now sends valid `origins` / `destinations` FeatureSets
  and reads the real `Total_TravelTime` / `Total_Kilometers` output attributes
  (kilometres converted to metres), replacing a malformed request/response path
  that returned no usable results.
- **Connector hardening (second review pass)** across the ESRI, Google, HERE,
  Mapbox, OSRM, and TomTom connectors — malformed-`200`-response guards and
  coordinate finiteness validation so every failure surfaces as a
  `ConnectorError`.

## [1.0.1] — 2026-07-20

### Changed

- **Google routing & matrix** now classify an invalid or restricted API key as
  `auth_failed` (previously `invalid_request`). Google's Routes/RouteMatrix APIs
  return HTTP `400 INVALID_ARGUMENT` for a bad key, so the connectors now read
  the structured `google.rpc.ErrorInfo` `reason` from `error.details[]` (e.g.
  `API_KEY_INVALID`, `API_KEY_*_BLOCKED`, `SERVICE_DISABLED`) and map auth/quota
  reasons before falling back to the HTTP-status mapping. Absent an `ErrorInfo`,
  behaviour is unchanged.
- **Per-connector READMEs** no longer carry a YAML frontmatter block — the
  metadata GitHub rendered as a table but nothing consumed. The rate-limit-docs
  links it held now live in the README prose; the frontmatter validator
  (`scripts/validate-frontmatter.php`), its schema, and the CI gate that ran it
  have been removed.

### Security

- **API keys no longer leak through the exception chain.** On a transport
  failure `dispatch()` redacted the request URL from the error message but still
  attached the raw PSR-18 exception — whose message embeds the full URL,
  including `?key=…` / `token=` — as both `cause` and `previous`, so
  `(string) $error`, `$error->getPrevious()`, `error_log()`, and Monolog's
  `['exception' => $e]` re-exposed the credential (CWE-532). The raw exception is
  no longer chained; only a non-sensitive class descriptor is attached.

## [1.0.0] — 2026-06-04

First public release of `thinwrap/location` — the lightweight, SDK-free
location API wrapper for routing, distance matrix, geocoding, and isochrone
across six providers.

### Public surface (locked at v1.0)

- **4 unified facades**: `Routing`, `Matrix`, `Geocoding`, `Isochrone` in the `Thinwrap\Location\` namespace.
- **21 per-provider × operation connectors**:
  - Google: `GoogleRoutingConnector`, `GoogleMatrixConnector`, `GoogleGeocodingConnector` (3).
  - Mapbox: `MapboxRoutingConnector`, `MapboxMatrixConnector`, `MapboxGeocodingConnector`, `MapboxIsochroneConnector` (4).
  - HERE: `HereRoutingConnector`, `HereMatrixConnector`, `HereGeocodingConnector`, `HereIsochroneConnector` (4).
  - ESRI: `EsriRoutingConnector`, `EsriMatrixConnector`, `EsriGeocodingConnector`, `EsriIsochroneConnector` (4).
  - TomTom: `TomTomRoutingConnector`, `TomTomMatrixConnector`, `TomTomGeocodingConnector`, `TomTomIsochroneConnector` (4).
  - OSRM: `OsrmRoutingConnector`, `OsrmMatrixConnector` (2).
- **Polyline utilities**: `Polyline::encodePolyline`, `Polyline::decodePolyline`, `Polyline::decodeFlexPolyline`, `Polyline::encodeEsriPaths`.
- **Error model**: `ConnectorError` (extends `\RuntimeException`) + 11-value
  `ProviderCode` string-backed enum (6 canonical + 5 location-extended).
- **Coordinate type**: `LatLng` DTO.
- **Config types**: `GoogleConfig`, `MapboxConfig`, `HereConfig`, `EsriConfig`,
  `TomTomConfig`, `OsrmConfig`.

### Properties

- **Zero vendor SDK dependencies** — uses PSR-18 HTTP client (BYO via
  `php-http/discovery` or explicit injection).
- **Cosign keyless provenance** — every release source archive is
  cosign-signed via GitHub OIDC; no static private key, no long-lived
  Packagist token. PHP analogue of npm Sigstore provenance.
- **Wrapper holds no state** — no token cache, no connection pool, no retry
  buffer. Every operation is a single method call from input to output.
  Consumers compose retry / caching / lifecycle out-of-band via PSR-18
  middleware or framework hooks.
- **Bring-your-own PSR-18 client** — pass any PSR-18-compatible client
  (Guzzle, Symfony HttpClient, php-http/curl-client, …) for tracing,
  mocking, or custom transport.
- **PHPStan level 8 clean** on the public surface. Full readonly DTOs with
  promoted-constructor properties (PHP 8.2+).
- **PHP ≥8.2** — uses readonly properties, backed enums, native union
  types, and `mixed` for the connector-error `$cause` payload.
- **Cross-language parity** with `@thinwrap/location` (npm) — identical
  facade names, error model, result shapes, provider IDs. Byte-exact
  polyline encoding parity verified via fixture
  (`tests/Fixture/polyline/parity-vectors.json`).

### Baseline-coverage discipline

The unified facade surface includes only features ≥90% of providers natively
support. Sub-baseline fields are accessible via per-provider augmented input
DTOs and the `Passthrough` escape hatch.

### Migration

This is the first public release under the `thinwrap/location` name; there
are no prior published versions.

The README's Migration section documents shifting from third-party SDKs
(Google Maps PHP SDK, Mapbox Mapping Services SDK).

### Cross-language

Companion package `@thinwrap/location` publishes simultaneously on npm with
identical facade names, error model, and result shapes.

[1.0.0]: https://github.com/thinwrap/location-php/releases/tag/v1.0.0
