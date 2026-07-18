# Changelog

All notable changes to `paylod/paylod` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-07-18

Initial release. Ports the surface and behaviour of the official Node client (`@paylod/node`
v0.3.x) to idiomatic PHP 8.1+, with first-class Laravel support.

### Added

- `Paylod` client with API-key auth and a baked-in base URL (`https://paylod.dev/functions/v1`).
  - `collect()` - send an STK Push, returns a pending ack with the `Idempotency-Key` used.
  - `collectAndWait()` - collect then poll to a terminal, renderable outcome.
  - `status()` / `check()` - read a payment (raw / decoded).
  - `wait()` - poll with a jittered backoff ramp (1s -> 5s); throws `PaylodTimeoutError` on deadline.
  - `decodeError()` - offline Daraja result-code decoding.
  - `verifyWebhook()` (boolean) and `parseWebhook()` (typed event).
  - `simulator` - sandbox simulator (`simulate` mode) that drives payments to any of the five
    outcomes with no phone; refuses a `mp_live_` key locally.
- Webhook signing/verification (`Paylod\Webhook`): HMAC-SHA256 over `${t}.${rawBody}`,
  `t=,v1=` header, constant-time compare, 300s replay tolerance. Includes the shared
  `whsec_golden_vector_v1` cross-repo drift-guard vector.
- Offline Daraja error-code catalog, copied verbatim from the canonical monorepo source, with
  the classifier + decoder (`Paylod\DarajaCatalog`). `retryable` means SAFE TO CHARGE AGAIN.
- Kenyan MSISDN normalisation (`Paylod\Phone`).
- Typed error taxonomy under `Paylod\Exceptions`.
- Laravel integration: `PaylodServiceProvider` (singleton + config publish + package
  auto-discovery), `Paylod` facade, and a publishable `config/paylod.php`.
- Injectable HTTP transport (`Paylod\Http\Transport`), defaulting to `CurlTransport`, so the
  whole test suite runs with no network.

[Unreleased]: https://github.com/mosesmrima/paylod-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/mosesmrima/paylod-php/releases/tag/v0.1.0
