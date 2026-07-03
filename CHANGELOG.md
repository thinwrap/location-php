# Changelog

All notable changes to `thinwrap/location` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
