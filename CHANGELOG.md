# Changelog

All notable changes to `paylod/paylod` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-07-18

Third-round fixes from a codex re-verification of 0.3.0, which found a **Critical double-charge
path**. The golden webhook vector (`whsec_golden_vector_v1` -> `3afe38e4...2c2eb7`) still passes
byte-for-byte. Every fix below has a test that fails when the fix is reverted.

### Fixed (Critical)

- **`collectAndWait()` no longer loses the idempotency key when the wait fails.** This was a
  straightforward route to charging a customer twice. `collect()` attaches the effective key to any
  error it raises - but `collectAndWait()` called `wait()` **outside** that protection. So once the
  STK prompt was on the phone, *every* subsequent failure (the wait timing out, a network blip
  mid-poll, a 5xx, a malformed status body) surfaced an error with **no key on it**. An
  SDK-generated key at that point is irrecoverable: it existed only inside the call. The caller's
  natural response - retry `collectAndWait()` - mints a **fresh** key, which the idempotency layer
  correctly treats as a new charge. Two prompts, two payments, one order. Now every
  post-acknowledgement failure carries both the effective `idempotencyKey` and the `paymentId`
  (`PaylodException::$paymentId`, new), and a non-paylod throwable is wrapped in an indeterminate
  `PaylodApiError` that can carry them rather than escaping bare.
- **Wait options are validated BEFORE the charge is dispatched.** `collectAndWait(..., ['timeoutMs'
  => 0])` used to ring the customer's phone and *then* throw a config error, which reads to the
  caller as "nothing happened" while a payment is live.

### Fixed (High)

- **The COMPLETE acknowledgement schema is validated on every 2xx.** 0.3.0 checked only
  `paymentId`, so a 2xx with a missing or blank `checkoutRequestId`, a missing `status`, or a
  `status` of the wrong type returned "successfully" with an unusable shape - which the caller then
  treats as a new payment and retries under a new key. Every malformed 2xx is now a
  `PaylodApiError` with `indeterminate: true` carrying the effective key.
- **Status responses get the same treatment, plus an evidence rule.** A `status: "success"` is only
  believed when the body carries actual proof - an `mpesaReceipt`, or result code `0`. The status
  string alone is a claim, not a receipt; shipping goods against an evidence-free claim is a real
  loss. Unknown statuses and wrongly-typed `mpesaReceipt` / `resultCode` / `resultDesc` are
  rejected too.
- **Secrets no longer leak into traces, dumps, or error bodies.** Four separate holes:
  `#[\SensitiveParameter]` now marks every secret-bearing parameter (the constructor's key and
  options, `verifyWebhook()` / `parseWebhook()`'s `$secret`, the simulator's key), so PHP renders
  them as placeholders in stack traces - which matters because `zend.exception_ignore_args=0` is
  the development default and any uncaught exception would otherwise print a live money-moving key
  into the log. `__debugInfo()` on the client and the simulator means `print_r()` / `var_dump()`
  show masked prefixes (`mp_test_***`) instead of the private properties' real values. And server
  error bodies are now recursively redacted before an error is constructed, so a gateway that
  echoes the `Authorization` header back cannot put the key into an exception message. Anything
  merely *shaped* like a paylod credential (`mp_live_`/`mp_test_`/`whsec_`) is redacted too.
- **Fractional timeouts are refused.** `timeoutMs: 0.5` passed the "greater than 0" check and then
  truncated to `0` on the cast - and `0` **disables** cURL's timeout entirely, so a request asking
  for half a millisecond would instead hang indefinitely. Timeouts must now be finite, whole
  integers in 1..600000.

### Fixed (Medium/Low)

- **Retries are bounded and every sleep has a ceiling.** `maxRetries` was unbounded and coerced
  with `(int)`, while the backoff doubles each attempt - so a config typo did not merely retry more,
  it slept geometrically longer (attempt 20 alone is over a day). `maxRetries` is now validated to
  0..10, and every backoff / `Retry-After` sleep is clamped to 60s **even when there is no
  deadline** (which is exactly `collect()`'s case).
- **`Retry-After` parsing hardened.** The lookup matched only the exact lowercase key, so a
  transport preserving `Retry-After` made the SDK ignore the server's explicit back-off; oversized
  digit strings overflowed to a float and raised a `TypeError` on a retryable 429 mid-payment; and
  `strtotime()` accepted non-HTTP-date forms (`now`, `+1 day`, ISO-8601). Lookup is now
  case-insensitive, only delta-seconds and a strict IMF-fixdate are accepted, and the arithmetic
  saturates rather than overflowing.
- **The simulator uses the production validators.** It was a second public dispatch surface with
  none of the guards: it accepted idempotency keys production rejects, and turned a malformed 2xx
  into an empty payment id instead of a keyed indeterminate error - so a test asserting "a
  double-click cannot charge twice" was not testing the real rules. The idempotency and
  acknowledgement/payment validators now live in `Paylod\Support\Validate` and are shared.

### Changed

- The first attempt of a request now always goes out, even against an already-expired deadline, so
  an operation never reports failure without having tried once.
- `PaylodTimeoutError::$paymentId` moved to the `PaylodException` base class (still public, no
  longer `readonly`) so every paylod error can carry it. Reading it is unchanged.

## [0.3.0] - 2026-07-18

Second-round money-path and security fixes from a codex re-verification of 0.2.0. The 0.2.0 pass was
directionally right but incomplete: each item below is a hole the first pass left open. The shared
golden webhook vector (`whsec_golden_vector_v1` -> `3afe38e4...2c2eb7`) still passes byte-for-byte.

### Changed (breaking)

- **`baseUrl` is now origin-ALLOWLISTED, not merely HTTPS-checked.** 0.2.0 accepted any `https://`
  host, so a mistyped or attacker-supplied `PAYLOD_BASE_URL` would send a live `mp_live_` bearer key
  to an arbitrary origin. Only `paylod.dev` and `api.paylod.dev` are accepted. Also rejected now:
  URL userinfo (`https://paylod.dev@evil.example`), a missing host, a non-443 port, a query string or
  fragment, and raw / private / link-local IPs. The explicit test-only loopback exception is kept -
  `['allowInsecureBaseUrl' => true]`, loopback host, and never with an `mp_live_` key.
- **`timeoutMs` must be positive and bounded (1..600000 ms).** 0 and negatives were accepted and
  passed straight through to `CURLOPT_TIMEOUT_MS`, where **0 disables the timeout entirely** - a hung
  request would never return and a `wait()` would never settle. Applies to both the constructor
  option and `wait(['timeoutMs' => ...])`.
- **`idempotencyKey` must be printable ASCII (0x20-0x7E).** HTTP header values are ASCII on the wire
  (RFC 9110), but a printable non-ASCII key - `ordr-cafe-1` with an accented `e`, a CJK order id, an
  en dash pasted from a document - passed every other rule: it is not blank, not a control character
  and not invisible whitespace. Such a key either fails in the transport as an unactionable encoding
  error, or on a laxer stack is silently re-encoded, so two requests meant to carry **one** key stop
  matching and the duplicate-charge guard vanishes without a sound. Rejected locally, before dispatch,
  with an error that says what to use instead: derive keys from an id you control (a UUID, or an order
  id slugged to ASCII).
- **A non-positive webhook tolerance is now refused UNCONDITIONALLY.** 0.2.0 still allowed
  `toleranceSec: 0` when a fixed `$nowSec` was injected, which meant the public verifier could be
  called with freshness effectively disabled. `toleranceSec` must be a finite, whole, positive number
  of seconds; `NAN` in particular is rejected because `abs($now - $t) > NAN` is false and would have
  passed *every* stale signature. The injected `$nowSec` clock is validated the same way. To verify a
  pinned fixture, keep a normal window and inject the fixture's own timestamp - which is what the
  golden-vector test now does.

### Money-correctness

- **Family-aware decoding no longer falls back to an STK pending entry.** `DarajaCatalog::decode()`
  kept an "any match" fallback, so an explicitly non-STK code with no non-STK catalog entry - e.g.
  `4999` with family `api_error` or `b2c_c2b_result` - still decoded as the STK **pending** entry and
  told the caller to keep polling an error that will never settle. A non-STK family now selects only
  the requested entry or another non-STK entry, and otherwise returns a terminal, non-retryable
  failure. The STK surface is unchanged: there, `4999` really is an in-flight payment.

### Security

- **Strict lexical timestamp parsing.** The signature header's `t` is validated as decimal digits
  only. `filter_var` alone accepted `1e3`, `+1000` and other coerced forms, so the value used for the
  freshness check could differ from the text that was HMAC'd.
- **Idempotency key charset tightened.** The C0-only byte check missed the **C1 range**
  (U+0080-U+009F) and Unicode-only whitespace (NBSP, ideographic space, BOM, line/paragraph
  separators), all of which survive `trim()`. Two visually identical keys could therefore become two
  different charges. Keys must now be valid UTF-8, free of C0/C1/DEL and Unicode-only whitespace, and
  at most 255 **bytes** (an HTTP header value is bytes, not characters).

### Internal

- The wait/poll absolute deadline is now proven, not just intended, to cap every in-flight request
  timeout and every backoff / `Retry-After` sleep; the mock transport records the per-request timeout
  so a regression is caught by a test rather than by a stuck poller.

## [0.2.0] - 2026-07-18

Money-correctness and security hardening ported from the canonical Node client (`@paylod/node`
v0.4.0), plus PHP-specific fixes from a codex review. The shared golden webhook vector
(`whsec_golden_vector_v1` -> `3afe38e4...2c2eb7`) still passes byte-for-byte; only
parsing/validation got stricter.

### Changed (breaking)

- **Minimum PHP is now 8.2** (was 8.1) - required for `#[\SensitiveParameter]`.
- **`Webhook::verify()` / a non-positive `toleranceSec` is refused** (`insecure_tolerance`) unless a
  fixed `$nowSec` is injected, instead of silently disabling replay protection. Verifying an ancient
  fixture with `toleranceSec: 0` now requires pinning the clock via `$nowSec`.

### Money-correctness

- **Raw `status` can no longer override the classifier.** `PaymentOutcome::fromPayment()` derives
  the outcome from `classifyStkResult` alone whenever a result code is present; a `status:"success"`
  row carrying a pending code (`4999`) or a failure code (`1032`) is no longer reported as paid. A
  genuine contradiction between the two terminal signals is now **indeterminate** - not paid, not
  retryable - surfaced as `pending` so `wait()` lets it settle. (`src/PaymentOutcome.php`)
- **Catalog `retryable` flags corrected (owner-approved).** Codes **17, 26, 1025, 9999** changed
  from `retryable:true` to `retryable:false`; provenance recorded in each entry's `sources[]`.
  Catalog copied verbatim from the canonical source. (`src/resources/daraja-error-codes.json`)
- **Family-aware decoding.** `DarajaCatalog::decode()` no longer routes every code through the STK
  classifier: dotted `api_error` codes and alphanumeric `b2c_c2b_result` codes now decode terminally
  by family. The overloaded `500.001.1001` decodes as the terminal server error on `api_error` and
  as "still processing" on STK, and `"insufficient funds"` was added to the terminal-500 matcher.
  (`src/DarajaCatalog.php`)

### Idempotency / double-charge

- **Idempotency keys are validated.** Blank, whitespace-only, control-character, and over-long keys
  are rejected up front in `collect()` instead of silently dropping double-charge protection.
- **A generated key is never lost on failure.** When `collect()` throws (network, timeout, 5xx,
  malformed 2xx) the effective idempotency key is attached to the thrown error (via
  `PaylodException::$idempotencyKey`) so a caller retries with the SAME key. (`src/Paylod.php`,
  `src/Exceptions/PaylodException.php`)
- **In-progress 409 handling.** Only an explicit `409` "already in progress" is retried (bounded,
  honouring `Retry-After`). Body-conflict and indeterminate 409s stay terminal. (`src/Paylod.php`)

### Security

- **HTTPS enforced on `baseUrl`.** A non-HTTPS origin is refused at construction. Loopback HTTP is
  permitted only behind the new **`allowInsecureBaseUrl`** test-only flag, and never with an
  `mp_live_` key. (`src/Paylod.php`)
- **Secrets scrubbed from traces.** The webhook signing secret (`Webhook::verify/isValid/sign`) and
  the transport `Authorization` header (`CurlTransport::send`) are marked `#[\SensitiveParameter]`,
  and transport connection errors are rethrown with the bearer key redacted.
  (`src/Webhook.php`, `src/Http/CurlTransport.php`, `src/Http/Transport.php`)

### Robustness

- **Malformed 2xx is indeterminate.** A `collect()`/`status()` 2xx with no payment id now raises an
  indeterminate `PaylodApiError` (new `indeterminate` flag; `collect()` also carries the idempotency
  key) instead of silently producing an empty id. (`src/Paylod.php`, `src/Exceptions/PaylodApiError.php`)
- **`wait()` respects its deadline.** The remaining deadline is propagated into every poll, and each
  request timeout plus every `Retry-After`/backoff sleep is capped to it. (`src/Paylod.php`)
- **Retries restricted to transient statuses.** `501`, `505`, `511` are no longer retried; only
  `429` and other 5xx are. `Retry-After` now parses both delta-seconds and HTTP-date. (`src/Paylod.php`)
- **Webhook header strictness.** The signature header must carry exactly one integer `t` and exactly
  one 64-char lowercase-hex `v1`; duplicate, malformed, or comma-combined multi-value headers are
  rejected. `t` is validated as an integer regardless of tolerance. (`src/Webhook.php`)
- **`curl_close()` removed** - the `CurlHandle` lifecycle is automatic and `curl_close()` warns on
  PHP 8.5. (`src/Http/CurlTransport.php`)

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

[Unreleased]: https://github.com/mosesmrima/paylod-php/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/mosesmrima/paylod-php/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/mosesmrima/paylod-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/mosesmrima/paylod-php/releases/tag/v0.1.0
