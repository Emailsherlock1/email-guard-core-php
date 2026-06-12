# Changelog

## [0.1.0] - 2026-06-13

First release. Implements email-guard-spec 1.0.0.

### Added

- Local checks: syntax profile, reserved-TLD list, bundled disposable
  snapshot (73k+ domains, exact matching).
- Decision model: `block_on` / `review_on` / `fail_open`, degraded results
  bypass policy lists per spec.
- Verify API escalation via `CurlTransport` (default) or any PSR-18 client
  via `Psr18Transport`.
- Full conformance suite: all spec vectors green, `api_called` asserted.
