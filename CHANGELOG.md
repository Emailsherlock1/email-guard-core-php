# Changelog

## [0.1.1] - 2026-06-13

### Fixed

- A broken or missing bundled snapshot no longer throws out of `check()`:
  the disposable check is skipped with an E_USER_WARNING and the remaining
  checks keep working. The guard never takes a form down.
- An empty-string `api_key` (typical for unset env vars resolved through
  config layers) is treated as null instead of triggering doomed API calls.


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
