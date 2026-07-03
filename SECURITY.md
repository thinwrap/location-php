# Security Policy

## Supported versions

`thinwrap/location` follows semantic versioning. Security fixes land on the
latest `1.x` release line. Pin to `^1.0` to receive them.

## Reporting a vulnerability

Please report security issues **privately** — do not open a public GitHub issue
for anything security-sensitive.

- Preferred: open a [private security advisory](https://github.com/thinwrap/location-php/security/advisories/new)
  on the repository (GitHub → Security → Report a vulnerability).
- Alternatively, email **dima@goryde.com** with the details.

Please include a description, affected versions, and a minimal reproduction if
you have one.

## Response targets

- Acknowledgement of your report: within **3 business days**.
- Initial assessment and severity triage: within **7 business days**.
- We will keep you updated on remediation progress and coordinate a disclosure
  timeline with you before any public advisory.

## Release integrity

Releases are cosign-signed via GitHub Actions OIDC (no static signing keys);
maintainer accounts require two-factor authentication (TOTP via an authenticator
app). Packagist consumes the package via webhook auto-sync — no long-lived
Packagist API token is stored.
