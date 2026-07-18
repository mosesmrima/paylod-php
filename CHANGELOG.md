# Changelog

All notable changes to `paylod/paylod` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.6.0] - 2026-07-18

**Breaking.** A fifth independent review found seven money-correctness defects and five mediums.
They were real, and they are fixed here. Every protection below is verified NON-VACUOUS by
`scripts/non-vacuity.php`, which reverts it in source and requires the guarding test to fail:
**32/32 mutations caught** (up from 18/18). The golden webhook vector
(`whsec_golden_vector_v1` -> `3afe38e4...2c2eb7`) still signs byte-for-byte identically.

### Breaking

- **`collect()` now REQUIRES a caller-persisted `idempotencyKey`.** Omitting it threw nothing
  before: the SDK generated one and emitted a once-per-process `E_USER_WARNING`. A generated key is
  not idempotency - it is a different value on every invocation, so it collapses nothing, and a
  double-clicked Pay button, a refreshed tab, a redelivered queue job or a process restart each
  raise a SEPARATE charge. The guard was therefore off by default, behind a warning that is
  invisible in every production posture that matters (`display_errors=0`, a log nobody reads, a
  handler that swallows `E_USER_WARNING`, or simply the second request in a worker's lifetime).

  The refusal happens BEFORE any byte leaves the process, so no prompt can already be on a handset.
  The escape hatch is explicit - `'unsafeGeneratedIdempotencyKey' => true` - and warns on EVERY
  call, not once.

- **`failed` / `cancelled` beside an in-flight result code is now `INDETERMINATE`, not
  `in_flight`.** The record contradicts itself and the SDK no longer picks a winner. What a merchant
  sees is unchanged: `PaymentOutcome` renders indeterminate as `pending`, so `wait()` keeps polling
  and the webhook settles it. What changed is that the SDK stops CLAIMING to know.

- **`Webhook` tolerance is now capped at `Webhook::MAX_TOLERANCE_SEC` (3600s).** A tolerance above
  that is refused with `insecure_tolerance`.

### Money correctness

- **`DarajaCatalog::classifyStkResult()` matches success EXACTLY, never numerically.** It read
  `is_numeric($raw) && $raw + 0 == 0`, and PHP's numeric-string grammar is far wider than the
  schema's: `"0e999"`, `"+0"`, `"00"`, `"0.0"`, `"-0"`, `" 0 "` and float `-0.0` all coerced to zero
  and classified `success`. A malformed, truncated or hostile record carrying any of them became
  `EVIDENCE_SUCCESS`, and under `status: "success"` became **PAID** - a merchant ships goods on
  that. Success is now identity against integer `0` or exact string equality with `"0"`. A terminal
  code must additionally be canonically shaped (`^[1-9][0-9]*$`); anything else is `pending`, which
  can never be paid and never retryable.

- **`Semantics::judge()` has no default branch at all.** Four permissive `default` arms survived the
  previous round. The claim is now normalised into a closed five-member alphabet by `claimFor()`,
  and the verdict is a single `match` over the 25 `claim|evidence` pairs with **no default arm**: a
  missing cell is an `UnhandledMatchError`, not a silent guess. Every contradiction maps to
  `INDETERMINATE`. `SemanticsTest` walks the FULL generated cross-product and looks each pair up in
  a pinned map, asserting both are exactly 25 entries - so a missing cell fails a test rather than
  reaching a caller.

- **A post-acknowledgement failure OVERWRITES the error's idempotency key and payment id** with the
  acknowledgement's, via the new `PaylodException::bindToAcknowledgedPayment()`. The best-effort
  `attach*()` semantics preserved whatever the error already carried - and an error thrown from an
  `onPoll` callback can carry an UNRELATED charge's context. The caller then read the wrong payment,
  concluded nothing had happened, and re-charged under a fresh key.

- **Wait deadlines run on `hrtime(true)`, not `microtime()`.** The wall clock moves - an NTP step, a
  DST transition, `date -s`. Backwards, a `wait()` hangs for as long as the clock was set back.
  Forwards, it expires INSTANTLY and throws `PaylodTimeoutError` on a payment whose STK prompt is
  live on the customer's handset; a caller that treats a timeout as "start again" charges twice.

- **`CurlHttpClient` buffers through a bounded write callback** with an 8 MiB ceiling
  (`MAX_RESPONSE_BYTES`), aborting the transfer as the bytes arrive rather than after. Previously
  `CURLOPT_RETURNTRANSFER` buffered without any bound, so the peer chose the allocation size and an
  endless body killed the process AFTER the charge had been dispatched - the worst possible moment,
  because the natural recovery is to run it again. Overflow raises a KEYED, INDETERMINATE
  `PaylodApiError`, deliberately not a `PaylodConnectionError`: connection errors are RETRIED.

### Credential hygiene

- **Wrapped exceptions no longer chain the original throwable as `previous`.** The wrapper's message
  was carefully redacted and then the un-redacted original was attached beside it:
  `getPrevious()->getMessage()` still held the echoed bearer token, and PHP's default
  `__toString()` WALKS the chain and prints it into the log line the framework writes. The
  original's trace is worse - with the development default `zend.exception_ignore_args=0` it records
  the call arguments of the frames that were handed the credential. A sanitized surrogate carrying
  the original's class name and its redacted message takes its place.

- **`status()` redacts every field it returns.** Redaction had been an error-path measure, on the
  reasoning that a `2xx` is "our own" data. It is not - it is bytes from the network, and the same
  misconfigured gateway that quotes the `Authorization` header into a 400 can quote it into a 200,
  most plausibly into `resultDesc`, which is free text handed to a caller who logs it, renders it,
  or pastes it into a support ticket.

### Laravel

- **`timeout_ms` and `max_retries` are validated LEXICALLY, before any cast.** The provider cast
  with `(int)` first, so `PAYLOD_TIMEOUT_MS=1.5` arrived at the client as a well-formed `1` - the
  client's own guard (which exists because a truncated `0` DISABLES cURL's timeout) never saw
  anything to complain about. The raw value is now inspected in the form the operator wrote it, and
  a non-integral one is refused by name, naming both the config key and the environment variable.

### Simulator

- **`array_key_exists` rather than `isset`, and every forwarded field type-checked.** `isset()` is
  false for a key that is PRESENT with a null value, so `['description' => null]` was silently
  dropped from the body the idempotency layer FINGERPRINTS - letting a reused key with a changed
  field replay in the simulator while production, which sees the difference, answers 409. A
  simulator that disagrees with production about what a request IS teaches the wrong lesson, and
  the lesson in question is "this cannot charge twice".

### Documentation

- **`SECURITY.md`** states the threat model explicitly - what is in scope (network attackers and
  MITM, malicious API responses, cross-origin redirect credential capture, wrong-record settlement,
  webhook forgery and replay, double-charge through idempotency mishandling, accidental credential
  disclosure) and what is not (an adversary who can already execute code in the same PHP process,
  host compromise, malicious dependencies already loaded). It is deliberately specific about the
  limits of the closure-based secret storage: it resists `var_dump`, `print_r`, `var_export` and
  serialization, but an attacker who can cast the client with `(array)` and invoke the resulting
  closures can still recover the token. It is defence against accidental disclosure, not a security
  boundary. Identical in substance across the paylod SDKs.

## [0.5.0] - 2026-07-18

**Breaking.** Two ARCHITECTURAL roots are closed here rather than patched. Rounds 1-4 fixed
findings one at a time and did not converge, because the findings were symptoms: the SDK had no
model of what a payment record MEANS, and it handed a money-moving credential across a replaceable
boundary. Both are now closed by construction - the misuse is no longer expressible.

Mirrors `@paylod/node` 0.7.0 law-for-law. The golden webhook vector (`whsec_golden_vector_v1` ->
`3afe38e4...2c2eb7`) still passes byte-for-byte. Every protection below is verified NON-VACUOUS by
`scripts/non-vacuity.php`, which reverts it in source and requires the guarding test to fail:
**18/18 mutations caught.**

### ROOT 1 - the transport owns the credential

- **`Paylod\Http\Transport` is no longer an interface.** It was, and it received the fully-built
  `Authorization: Bearer ...` header, so every protection after that point was a suggestion: an
  injected transport could follow a cross-origin 302 itself and hand back an ordinary `200` from
  another host, with the credential already replayed, and could put the header into its own
  exception traces. Checking after following is too late.

  `Transport` is now a **final class that owns the API key**. Callers pass a method, a path and a
  body; they never see the credential, never construct headers, never supply a URL and never choose
  a redirect mode.
- **The origin is pinned per dispatch**, recomputed and compared on every single request rather
  than once at construction.
- **Redirects are refused three ways**: a `3xx` status, a non-zero cURL redirect count (a client
  that FOLLOWED one despite being told not to - a detection, since the credential is already
  burned), and an effective URL off the pinned origin. `CurlHttpClient` sets
  `CURLOPT_FOLLOWLOCATION => false` and pins TLS peer/host verification.
- **A custom HTTP client is a GATED TEST SEAM.** The low-level byte mover is now
  `Paylod\Http\HttpClient`. Supplying one requires an explicit `allowCustomHttpClient => true`
  and is **refused outright for `mp_live_` keys** - the same posture `allowInsecureBaseUrl` already
  had. Both rules are enforced at the client AND again inside `Transport`, so the transport holds
  the line on its own terms and cannot be reopened by a future caller.

### ROOT 2 - the semantic model

`Paylod\Semantics` is new and is now the ONLY place that decides whether a payment is paid. A
record makes ONE **CLAIM** (`status`) and carries **EVIDENCE** (`mpesaReceipt`, `resultCode`);
neither substitutes for the other. `evidenceFor()` derives what a record proves without looking at
what it claims, and `judge()` resolves the two through a **TOTAL table with no default branch** -
the defaults are exactly where the old per-field logic went wrong.

Four laws, asserted directly in `tests/SemanticsTest.php`:

| | Law | Rule |
| --- | --- | --- |
| **L1** | BINDING | A body whose `id` is not the id that was requested is never evaluated at all. |
| **L2** | EVIDENCE | `paid` requires a receipt **or** result code 0. Success *without* a receipt stays legitimate - receipts attach asynchronously - so a receipt is never required outright. |
| **L3** | CONSISTENCY | A claim contradicting its evidence is INDETERMINATE - never a failure, and never a *retryable* one. |
| **L4** | RECEIPT | A receipt present forces `paid` or `indeterminate`. Never `failed`, never `in_flight`. |

**Behaviour that changed** (all three were verified against the previous build):

- `{status: "pending", resultCode: 0}` was **paid**, with a null receipt -> now **indeterminate**.
- `{status: "failed", mpesaReceipt: "SFF6XYZ123", resultCode: 1032}` was `cancelled` with
  **`retryable: true`** -> now **indeterminate**. This one told a merchant it was safe to charge
  again for a payment carrying an M-Pesa confirmation receipt. It is the single worst defect a
  payments SDK can have.
- `{status: "pending", mpesaReceipt: ...}` was paid -> now **indeterminate**.

An indeterminate payment renders as `pending`, so `wait()` keeps polling and lets the webhook
settle it rather than reporting a false success or a false retryable failure.

### Fixed (Critical / High)

- **`PaymentOutcome::fromPayment()` no longer bypasses the rules.** It carried its own copy of the
  logic - a `contradictory` boolean, a `$classified ?? $rawStatus` fallback chain, and an evidence
  check nested inside the success branch - and the GAPS BETWEEN those three were the holes above.
  It now renders `Semantics::judge()` and decides nothing, so law L2 holds inside it by
  construction regardless of which surface built the array or whether a validator ran first.
- **Status reads BIND to the requested id (L1).** Nothing previously compared the `id` in the
  response to the id in the request, so any mechanism returning a different payment's record - a
  mis-keyed cache, a proxy collapsing concurrent requests, an authorization bug, a crafted response
  - produced a body the SDK validated happily and classified on its own merits. If that other
  payment was paid, the caller shipped goods for an order nobody had paid for.
- **Collect acks require HTTP `202` and the literal `status: "pending"`.** Any 2xx used to pass, so
  a bare `200` - what a cache, a proxy, a captive portal or a rewritten route produces - read as a
  successfully dispatched charge.
- **A non-Paylod throwable no longer escapes `collect()` bare.** It carried neither the idempotency
  key nor an indeterminate classification, so the caller's natural retry minted a FRESH key and
  charged the customer twice. It is now wrapped in a keyed, indeterminate `PaylodApiError`.
- **`var_export()` no longer prints the API key or the webhook secret.** `__debugInfo()` covers
  `print_r()` and `var_dump()` but `var_export()` ignores it entirely and walks the real
  properties - and `var_export()` is what config dumpers and cache warmers call. Both secrets now
  live in closures, which `var_export()` renders as `\Closure::__set_state(array())`. Serialising
  a client is refused outright for the same reason.
- **Signed payment webhooks run through the same `judge()`.** Envelope validation was shallow:
  `data.status`, `data.mpesaReceipt` and `data.resultCode` were whatever arrived, and a handler
  written the natural way would fulfil an order on a field nothing had checked. A valid signature
  proves the body came FROM paylod and says nothing about whether it is COHERENT. Payment events
  now get shape, type/status consistency, and evidence via `Semantics::judge()`.
- **The simulator runs the validators INSIDE the request.** It validated after the fact against a
  hardcoded `200`, so it could not enforce the 202 contract; it dispatched `collect()` with no
  idempotency key when one was omitted, and its settle call carried none at all; and `outcome()`
  did not bind. All four are fixed - a simulator weaker than the path it stands in for makes every
  test written against it a lie.

### Breaking changes

- The `transport` option and the `Paylod\Http\Transport` **interface** are removed. `Transport`
  is now a final, SDK-owned class. For tests, pass
  `['httpClient' => $client, 'allowCustomHttpClient' => true]` with an `mp_test_` key. Passing
  `transport` throws a `PaylodConfigError` naming the replacement.
- `Paylod\Http\CurlTransport` is now `Paylod\Http\CurlHttpClient`, implementing
  `Paylod\Http\HttpClient`.
- `Webhook::verify()` / `isValid()` now reject correctly-signed but incoherent payment events.
  `Webhook::verifySignature()` / `isValidSignature()` expose the signature-only layer.
- `POST /collect` responses that are not `202` with `status: "pending"` are rejected.
- `GET /status/:id` responses whose `id` differs from the requested id are rejected.
- An evidence-free `status: "success"` status body is no longer a thrown validation error; it is an
  INDETERMINATE outcome (`paid: false`, `retryable: false`, rendered as `pending`), because an
  indeterminate payment must keep being polled rather than abort `wait()`.

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
