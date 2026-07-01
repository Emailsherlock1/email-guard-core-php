# Changelog

## [0.4.0] - 2026-06-30

### Added

- Granular degraded reasons (email-guard-spec 1.3.0, section 5.2). A failed API
  escalation still reports `api_unavailable`, but `EmailGuard` now appends the
  attributed cause: `api_timeout` (curl error 28 / connect-timeout),
  `api_unreachable` (DNS / TLS / connection), `api_http_5xx` / `api_http_4xx`
  (non-2xx status), or `api_bad_response` (a 2xx answer with a non-JSON body).
  The new reasons flow through telemetry (`reasons[]`) unchanged, so the guard
  dashboard can tell a latency problem apart from an API-health problem.
- `TransportException` now carries a `$kind` (and `$statusCode` for HTTP
  failures). `CurlTransport` classifies curl error 28 as a timeout and every
  other `curl_exec` failure as a connection error; `Psr18Transport` reports
  client exceptions as connection failures (PSR-18 cannot tell a timeout apart).

### Changed

- SPEC_VERSION is now 1.3.0; conformance vectors updated (`fail-open.json`
  `fo-007` … `fo-012`). `fail_open` behavior is unchanged: a degraded check
  still allows (or denies, with `fail_open: false`) exactly as before, and an
  unattributed failure still reports `api_unavailable` alone.

## [0.3.0] - 2026-06-19

### Added

- `relay` signal (email-guard-spec 1.2.0, section 6.5). Extracts `relay` /
  `relay_provider` from the Verify API response and exposes them on `Result`
  (`$relay`, `$relayProvider`). An integrator can deny or review relay /
  forwarding / alias-masking addresses (SimpleLogin, Apple Hide My Email,
  Cloudflare Email Routing, …) via `block_on: ['relay']` / `review_on: ['relay']`.
  Inert by default: a relay address is deliverable and reaches a real person,
  just masked, so it passes unless opted in. When active, the reason `relay`
  (`Reason::RELAY`) is appended so telemetry can attribute the decision.

### Changed

- SPEC_VERSION is now 1.2.0; conformance vectors include `relay.json`.

## [0.2.0] - 2026-06-13

### Added

- `GuardReporter`: decision-telemetry emitter (email-guard-spec section 11).
  Records one event per `check()` decision and ships them batched to
  `POST /v1/guard/events`. Key-gated (no key, no telemetry), fail-silent,
  domain only (never the address). `EmailGuard` takes an optional reporter
  and auto-records after each check; the caller decides when to `flush()`.
- `GuardDecisionSource` (local|remote) constants.

### Changed

- SPEC_VERSION is now 1.1.0 (the lib implements the telemetry contract).


## [0.1.2] - 2026-06-13

### Changed

- The bundled snapshot now embeds a pinned release of
  [email-guard-data](https://github.com/Emailsherlock1/email-guard-data)
  (v2026.06.13, exported from the EmailSherlock live list) instead of the
  raw upstream list. One source, consumed by every core library.
- `bin/build-snapshot.php` validates the artifact against the
  email-guard-disposable/1 format before embedding.


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
