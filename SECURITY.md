# Security

This document states, explicitly, what the paylod PHP SDK defends against and what it does not.
It is identical in substance across the paylod SDKs (`paylod/paylod`, `@paylod/node`, the Python
and JVM clients); only the code references differ.

The point of writing it down is that an unstated boundary gets assumed to be wherever the reader
hopes it is. Where a measure is partial, this document says so plainly rather than describing it in
language that implies more than it delivers.

## Reporting a vulnerability

Email **security@paylod.dev**. Please do not open a public issue for a suspected vulnerability.
Include a description, affected version, and a reproduction if you have one.

---

## In scope

These are threats the SDK is built to resist. A failure of any of them is a bug, and a security bug.

### 1. Network attackers and MITM

An attacker positioned on the network between your server and the paylod API.

- TLS peer and hostname verification are pinned explicitly (`CURLOPT_SSL_VERIFYPEER`,
  `CURLOPT_SSL_VERIFYHOST => 2`) rather than left to defaults, so a lax `php.ini` or a distribution
  build cannot silently downgrade them.
- The base URL must be a secure, canonical origin, checked **before** the API key can leave the
  process. Plaintext HTTP is refused; loopback HTTP requires an explicit `allowInsecureBaseUrl`
  opt-in and is refused outright for `mp_live_` keys.
- The origin is re-pinned and re-compared **on every single dispatch**, not once at construction.

### 2. A malicious or compromised API response

The response is not trusted because it arrived over the right connection with the right status code.

- A payment record is judged by an explicit semantic model (`src/Semantics.php`) that separates what
  a record **claims** (`status`) from what it **proves** (`mpesaReceipt`, `resultCode`). The
  resolution is a total table over the closed cross-product of claims and evidence kinds, with **no
  default branch** - a missing cell is an error, never a permissive guess.
- Every contradiction resolves to `indeterminate`: never `paid`, and never a *retryable* failure.
  We cannot prove money did not move, so we must never invite a second charge.
- Success evidence is matched **exactly**, never by numeric coercion. `"0e999"`, `"+0"`, `"00"` and
  `"-0"` are all `is_numeric` and loosely equal to zero in PHP; none of them is a representation the
  schema defines, and none of them proves a payment settled.
- A malformed `2xx` acknowledgement is `indeterminate` and carries the effective idempotency key -
  not a silent empty success.
- Response bodies are read through a bounded buffer with an 8 MiB ceiling, enforced as bytes
  arrive. A peer cannot choose how much memory this process spends; the ceiling matters because
  exhaustion would happen *after* a charge was dispatched.

### 3. Cross-origin redirects attempting to capture the bearer token

- The API key lives **inside** a `final` transport that builds its own headers and URL. Caller code
  never receives it, never constructs an `Authorization` header, and never chooses a redirect mode.
- Redirects are **disabled**, not merely inspected afterwards (`CURLOPT_FOLLOWLOCATION => false`,
  `CURLOPT_MAXREDIRS => 0`). A check performed after a redirect is followed is a post-mortem: the
  credential has already been replayed to the other host.
- They are additionally refused three further ways, so that a client which followed one anyway is
  *detected*: a `3xx` status, a non-zero cURL redirect count, and an effective URL off the pinned
  origin.
- Supplying your own `HttpClient` is a **gated test seam**, not an extension point: it requires an
  explicit `allowCustomHttpClient => true` and is refused outright for `mp_live_` keys, at the
  client and again inside the transport.

### 4. A response for a DIFFERENT payment (wrong-record settlement)

- A status response must describe the payment that was **asked about**. The returned `id` is bound
  to the requested id at the transport boundary; a body describing a different payment is a hard
  error and never reaches the semantic model. A response that answers a different question is not a
  malformed response, it is a wrong one, and no field-level shape check finds it.

### 5. Webhook forgery and replay

- Signatures are verified with a constant-time HMAC-SHA256 comparison over the **raw bytes**.
  Re-serialising a parsed body is not guaranteed to reproduce the same bytes.
- The signature header is parsed strictly: exactly one `t` and exactly one `v1`, with `v1` required
  to be 64 lowercase hex characters. A duplicate of either key is fatal, closing the
  last-value-wins hole where two headers combined into one comma-joined value
  (`t=1,v1=<real>,t=9999999999,v1=<forged>`) would otherwise be accepted.
- The anti-replay window has **both edges**: it must be finite, positive, whole, **and at most
  `Webhook::MAX_TOLERANCE_SEC` (3600 seconds)**. Positivity alone is not a security property -
  `PHP_INT_MAX` is positive, finite and whole, and it makes every timestamp fresh, removing replay
  protection entirely while every other check still passes. `NAN` is rejected for the same reason
  (`abs($now - $t) > NAN` is `false`, so every staleness check would pass).
- A verified event is then run through the **same** semantic model as a polled record, so a signed
  `payment.success` with no evidence, or a signed `payment.failed` carrying a receipt, is refused
  rather than handed to your handler.

### 6. Double-charge through idempotency mishandling

This is the defect class we treat as most severe, because it costs a real person real money.

- `collect()` **requires** a caller-persisted `idempotencyKey`. A key the SDK generates for you is
  not idempotency: it is a different value on every invocation, so it collapses nothing, and a
  double-clicked Pay button, a refreshed tab, a redelivered queue job or a process restart each
  raise a separate charge. Mint one key per payment *attempt* and persist it before calling.
  The unsafe path exists (`unsafeGeneratedIdempotencyKey => true`), is named accordingly, and warns
  on every call.
- Keys are validated (printable ASCII, non-blank) so a malformed key cannot silently drop the
  protection.
- **Every** error path past a dispatched charge carries the effective idempotency key, including
  non-paylod throwables, which are wrapped rather than allowed to escape bare. An error without the
  key is a double-charge waiting to happen: the caller's natural retry mints a fresh one.
- Past an acknowledgement, the error's key and payment id are **overwritten** with the
  acknowledgement's authoritative values. Best-effort "attach if absent" is wrong there: an error
  thrown from an `onPoll` callback can carry an unrelated charge's context, and the caller would
  read the wrong payment, conclude nothing happened, and charge again.
- Retry policy is conservative. A `409` is retried only when it is explicitly "same key still in
  progress"; non-transient `5xx` statuses are never retried; and an oversized response raises an
  *indeterminate* API error rather than a *retryable* connection error, precisely so it is not
  re-POSTed.
- Wait deadlines run on a **monotonic** clock (`hrtime`). A wall-clock deadline moves with an NTP
  step, a DST transition or an administrator running `date -s`: forwards it expires instantly,
  reporting a timeout on a payment whose prompt is live on the handset - and a caller that treats a
  timeout as "start again" charges twice.

### 7. Accidental credential disclosure

Disclosure into logs, stack traces, exception messages, `var_dump` / `print_r` / `var_export` /
serialization output, or telemetry that a normal, well-intentioned application would plausibly
emit. This is the threat that actually leaks keys in practice.

- The API key and webhook secret are held behind **closures**, not string properties.
- `__debugInfo()` exposes only masked prefixes (`mp_live_***`), covering `print_r()` and
  `var_dump()`.
- `__serialize()` **throws**. A serialised client would carry a live key into a session, a cache
  entry, a queue payload or a debug log.
- Secrets are marked `#[\SensitiveParameter]` at every boundary that receives them, so PHP renders
  them as a placeholder in stack traces (PHP records call arguments whenever
  `zend.exception_ignore_args=0`, which is the development default).
- Redaction is applied to error messages, error bodies **and to the fields returned by a successful
  status read**. A `2xx` is network data too, and `resultDesc` is free text a caller logs, renders,
  and pastes into support tickets. Both the exact secrets this process holds and anything
  *shaped* like a paylod credential (`mp_live_` / `mp_test_` / `whsec_`) are scrubbed.
- Wrapped exceptions do **not** chain the original throwable as `previous`. Its message, its stack
  trace and its recorded call arguments can each carry the key, and PHP's default `__toString()`
  walks the chain into the log line your framework writes. A sanitized surrogate - the original's
  class name and its redacted message - takes its place.

---

## Out of scope

These are real threats. They are simply not threats a client library can address, and we would
rather say so than let a measure be mistaken for a boundary it is not.

### An adversary who can already execute arbitrary code in the same PHP process

If an attacker can run code inside your process, they can read process memory and walk the object
graph. **No in-process client library can defend against this, and none claim to** - this is equally
true of the stripe, twilio and aws-sdk clients, and of every other SDK that holds a credential in
order to use it.

Being specific about what this means for our own measures, because the difference matters:

> The closure-based secret storage resists `var_dump`, `print_r`, `var_export` and serialization.
> **But an attacker who can cast the client with `(array)` and invoke the resulting closures can
> still recover the token.** `(array) $client` exposes the private `Closure` properties, and calling
> one returns the API key.

That is not a flaw in the design; it is the design's actual scope. The closures exist so that a
config dumper, a cache warmer, an exception page or a queue worker logging its payload cannot print
a live key by accident - which is how keys leak in the real world. They are **defence against
accidental disclosure, not against hostile in-process code**. Treat them as a hygiene measure, not
as a security boundary, and do not build any control on the assumption that the key is unreachable
from code running beside it.

### Host compromise

An attacker with access to the machine, the filesystem, the environment, or a memory-reading
debugger has already won. Environment variables, `.env` files, config caches and process memory are
all readable at that point.

### Malicious dependencies already loaded

A hostile package in your `vendor/` directory runs in the same process with the same privileges.
It can replace autoloaded classes, register handlers, and read anything this library holds. Supply
chain integrity is a property of your dependency management (lockfiles, review, `composer audit`),
not something a client library can enforce from inside the same runtime.

---

## Operational guidance

- Keep the API key on a server. Never ship it to a browser, a mobile app, or any client you do not
  control - it is a bearer credential that moves money.
- Use `mp_test_` keys everywhere except production.
- Rotate a key immediately if it may have been exposed, including in a log you have since deleted.
- Persist an idempotency key per payment attempt, before dispatching the charge.
- Verify every webhook before acting on it, using the raw request bytes.
- Treat an `indeterminate` result as **stop and read**, never as **retry**. Read the payment with
  the attached key or payment id; open a new attempt under a new key only once you have established
  that nothing happened.
