# Changelog

All notable changes to `paylod/paylod` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added - the test chain now runs in CI (`.github/workflows/ci.yml`)

Until now this repository had no CI configuration of any kind, and `composer test` - including the
non-vacuity mutation harness - had only ever been run by hand before a release. The mutation counts
recorded in the entries below are real and were reproduced on demand, but they were certified by a
maintainer at a terminal, not by an automated pipeline. No release before this one was CI-verified,
and this entry does not claim otherwise.

From now on GitHub Actions runs the full `composer test` chain - catalog drift, PHPUnit, then the
102-case non-vacuity harness - on every push and every pull request, across PHP 8.2, 8.3 and 8.4.
Composer relays a script's nonzero exit and aborts the chain, so a single escaped mutation fails the
job. A final step asserts the harness left the working tree byte-identical, catching any failure of
its restore guard rather than letting mutated source survive the run.

### Fixed - the harness no longer depends on PHPUnit declining to colourise a pipe

`runTests()` read PHPUnit's summary line with `/Tests: (\d+),/`. Under `--colors=always` PHPUnit
paints that line as `\e[37;41mTests: 1\e[0m\e[37;41m, Assertions: 1\e[0m...`, placing the reset
sequence between the digit and the comma, so the pattern matched nothing and the test count silently
stayed 0.

This was never observed, because PHPUnit has no CI colour heuristic and does not colourise when its
stdout is a pipe - which is how `exec()` captures it. The harness was correct only by accident of a
child process's present-day default, and it would have broken the moment a future PHPUnit added such
a heuristic or a wrapper forced colour. It failed closed (a lost count reads as `BROKEN-SELECTOR`,
never as a false pass), so no past result is in doubt.

Fixed on both sides: the child is invoked with `--colors=never`, and the captured output is passed
through `stripAnsi()` before any parse, so forced colour cannot resurrect the defect either.

## [0.10.1] - 2026-07-20

Patch. No API change. Two fixes: an ordering defect in the customer-message layer that reported an
IN-FLIGHT payment as finished, and a re-sync of the vendored Daraja code table, which is now
mechanically pinned to its canonical home so it cannot drift again unnoticed.

### Fixed - a pending payment was described to the customer as one that had failed

`DarajaCatalog::safeCustomerMessage()` consulted its `SAFE_CUSTOMER_MESSAGES` override map
UNCONDITIONALLY, before testing whether the catalog's own text actually invited a retry. The map is
keyed by CODE ALONE, while the catalog is keyed by `(code, family)`. So a single override reached
every family a code appears in - and `500.001.1001` appears in two:

| family | category | canonical customer message |
|---|---|---|
| `api_error` | `mpesa_system` | M-Pesa returned an error and we cannot confirm the outcome. Do not pay again yet. |
| `stk_result` | **`pending`** | Check your phone and enter your M-Pesa PIN to complete this payment. |

The `api_error` override was applied to both. A customer with a LIVE M-PESA PROMPT ON THEIR HANDSET
was therefore told the payment could not be confirmed and to go and read their M-Pesa messages -
an in-flight payment reported as finished. That is the same class of defect as the 4999 bug, and it
is a wrong statement rather than merely a terse one.

Two further consequences, both now closed:

* PHP's runtime text diverged from the Node, Python and JVM SDKs on 17 of the 32 `(code, family)`
  pairs. The other three agreed with each other throughout.
* PHP disagreed with ITSELF. `errorCatalog()` returned the canonical string while `decode()`
  returned the override for the same entry, so the two public surfaces gave different answers to
  "what should this customer be told".

**The fix is a reorder, not a logic change.** `RETRY_INVITATION_RE` is now tested FIRST, and the
override map is consulted only when the catalog text genuinely invites another attempt. Nothing is
weakened: the regex requires an explicit invitation (`try again`, `retry`, `again in|shortly|later`)
and never matches a bare `again`, and every path out of the function for an invitation-bearing
non-retryable entry is still a replacement - a curated override where one exists, the generic
no-retry message otherwise. So a table re-sync still cannot introduce a retry invitation. No
`retryable` value changed, no override was deleted, and the regex is untouched.

Eleven `(code, family)` pairs still read differently in PHP than in the other three SDKs. Those are
pre-dispatch validation and configuration errors - an invalid MSISDN, a till used as a paybill, an
unknown C2B account reference - where the canonical text does invite a retry and PHP suppresses it.
Conformance requirement 3.7 permits this explicitly ("an SDK MAY enforce the stricter blanket
rule ... that is conformant, merely terser than necessary"), so it is left as it is.

### Fixed - the vendored Daraja catalog matched the canonical table again

`src/resources/daraja-error-codes.json` is a physical COPY of the paylod monorepo's
`supabase/functions/_shared/daraja/daraja-error-codes.json`; this SDK is a separate repo and a
separate publish artifact, so it cannot import across the boundary. Six `customerMessage` strings
had drifted behind the canonical table. All six had been rewritten upstream for the same reason:
they invited a retry ("Please try again in a moment") on outcomes where a debit is NOT disproven.
The canonical wording now tells the customer to check their M-Pesa messages first.

The six are `17`, `26`, `1001`, `1025` and `9999` on the `stk_result` surface, plus
`500.001.1001` on `api_error` - which is precisely the `NO_DEBIT_PROOF_STK_CODES` set that 0.10.0
carved out, confirming the same finding had landed canonically but never reached this copy.

No `retryable` value changed, and the `safeCustomerMessage` enforcement layer is untouched - it
already overrode these strings at runtime for non-retryable codes, which is why the suite stayed
green while the copy was stale. That is precisely the problem: the drift was invisible to tests.

### Added - `scripts/sync-daraja-catalog.php`

The copy is now GENERATED, mirroring the Node SDK's `sync-daraja-catalog.mjs`:

    php scripts/sync-daraja-catalog.php           # write the copy
    php scripts/sync-daraja-catalog.php --check   # exit 1 on drift

The monorepo is located at `../mpesa` by default, overridable with `MPESA_REPO=/path`. In
`--check` mode an absent monorepo warns and exits 0 - a published-package consumer has nothing to
compare against - while write mode treats it as an error. `--check` is wired into `composer test`
ahead of PHPUnit, so a full test run fails on drift rather than shipping it.

### Added - drift and duplicate-code guards (`tests/DarajaCatalogDriftTest.php`)

Four new tests, +50 assertions:

- The vendored copy is byte-identical to the canonical table. Skips with a clear message when the
  monorepo is absent; it never passes silently on a difference.
- Every `(code, family)` pair in the catalog is unique. `code` alone is NOT the key.
- Non-vacuity for the above: duplicate bare `code` values are asserted to EXIST - `0`, `2001` and
  `500.001.1001` each appear under two families, and `2001` carries opposite `retryable` verdicts
  on the STK and B2C/C2B surfaces. Without this the uniqueness test would be trivially true.
- `errorCatalog()` keys by `code` alone and so cannot represent that ambiguity. Its documented
  "STK wins" collision rule is now pinned by test rather than left implicit; the method's API and
  behaviour are unchanged, since altering either would be breaking.

## [0.10.0] - 2026-07-20

**Breaking.** The unit of work for this release is not a findings list, it is
`docs/SDK-CONFORMANCE.md` - the specification all four paylod SDKs are now measured against. Ten
review rounds produced 95 distinct findings across Node, PHP, Python and JVM, and the dominant
failure was never that a bug was hard to fix: it was that a fix landing in one SDK never reached
the other three. An SDK is conformant when it satisfies every requirement in that document and has
a non-vacuous test proving each.

Every protection below is verified NON-VACUOUS by `scripts/non-vacuity.php`: **101/101 mutations
caught** (up from 81/81), across **457 tests / 2269 assertions** (up from 412 / 1864). The golden
webhook vector still signs byte-for-byte identically.

### Fixed - CRITICAL: terminal failure now requires proof that no debit occurred

Requirement 3.7 / 1.5. `DarajaCatalog::terminalStkCodes()` was derived by SUBTRACTION - every
`stk_result` entry that was not `pending` and not `0`. That swept in codes 17, 26, 1001, 1025 and
9999, whose OWN entries in the catalog say a debit is not disproven:

> "a busy-system rejection is not proof no charge was raised"
> "the in-flight transaction may be your own earlier push, and charging again could double-charge"

The SDK's own data contradicted the verdict it reached from that data. `classifyStkResult()`
answered `failed`, `Semantics` saw `EVIDENCE_FAILURE`, and a `failed` claim resolved to
`VERDICT_FAILED` - a settled failure. That admitted signed `payment.failed` webhooks as final and
told merchants the payment was over, on payments that may have been charged. The customer-facing
message then said "Please try again."

Code 1001 carried the identical defect and was not in the review finding.

- `NO_DEBIT_PROOF_STK_CODES` states the property explicitly, in code rather than in the JSON table
  (a verbatim copy of the monorepo's canonical file, which must not be hand-edited here).
- `terminalStkCodes()` is the table INTERSECTED with that set; `inconclusiveStkCodes()` is its exact
  complement, so no code can fall between the two.
- `Semantics` gains `EVIDENCE_INCONCLUSIVE` with its own five rows, all INDETERMINATE. The verdict
  table is now 5 claims x 7 evidence kinds, still total and still with no default arm.
- Requirement 3.7's message rule is enforced for EVERY decoded entry, not just the four reported
  codes: 17 entries carried a retry invitation beside `retryable => false`, including the
  uncatalogued-code fallback itself. Non-retryable messages now fail closed onto a no-retry text.

The tests couple the partition to the catalog's own prose, so neither the set nor the table can be
edited into disagreement, and carry controls in both directions - an over-corrected model that calls
every failure indeterminate is equally non-conformant (requirements 3.5 and 8.5).

### Fixed - HIGH: the webhook credential scan reads the DECODED event, and knows every credential

Requirements 4.6 and 4.7. The scan was `Redact::contains($raw, [$secret])`: the raw body, and the
signing secret alone.

- JSON string values may be spelled with `\uXXXX` escapes, so a secret written as `whsec_...` is
  absent from the raw bytes and present in the decoded event. It slipped the refusal, was rewritten
  to `[redacted]`, and was DELIVERED to the handler.
- `verifyWebhook()` checked for no credential at all and answered a plain `true`; `parseWebhook()`
  checked the API key against raw bytes only, and did so after the coherence rules had already run.

`Redact::containsDeep()` walks the decoded structure - keys and string leaves, recursively - and
fails closed past the parse depth. Both client surfaces pass every configured credential through one
`Paylod::configuredCredentials()`, and the refusal happens before any semantic conclusion is drawn.

### Fixed - HIGH: webhook payment ids get the shared identifier grammar

Requirement 3.4. `data.paymentId` was checked with `trim($v) !== ''` and nothing else, so it
accepted an echoed bearer token, a JSON fragment, a 200-byte blob - and, because the decoded event
passes through the redactor before a handler sees it, the literal `[redacted]`. Every redacted event
would have correlated to the same order.

### Fixed - HIGH: the recovery scope is established before dispatch

Requirement 5.4. `collectAndWait()` called `collect()` OUTSIDE its recovery `try`, and
`Simulator::pay()` had the identical ordering. Once `collect()` returns the STK prompt is on the
customer's phone; a throwable landing before control entered the protected block escaped with no
idempotency key and no payment id, which a caller reads as "nothing happened". The `try` is now
entered first and the acknowledgement context is published through an out-parameter as soon as it
exists. A failure BEFORE acknowledgement is deliberately not rebound, and there is a control for it.

### Fixed - MEDIUM: the simulator captures its acknowledged payment id

Requirements 5.4 and 6.7. `Simulator::collect()`'s validator ran `Validate::collectAck()` and
nothing else, so a malformed 202 carrying a usable `paymentId` threw with the id discarded - telling
the caller "a charge may be live, go and read it" while withholding the id needed to read it.
`Paylod::collect()` also declared `$acknowledgedPaymentId` below the simulator branch, so a throw
from that branch made the catch read an UNDEFINED variable - and under a warning-to-exception
handler, that raised a second throwable from inside the catch, destroying the original exception and
the idempotency key with it.

### Fixed - MEDIUM: the adversarial sweep can now fail

Requirement 8.6. The sweep caught `\Throwable` and treated any exception as adequate, discarded
successful `parseWebhook()` results, and took no sink from the boolean surface - so it could not
detect escaped-credential delivery, and "covers every public type" was a claim in a comment. It now
asserts which branches ran, captures successful returns and the boolean verdict, sweeps both literal
and JSON-escaped credentials plus a non-credential-shaped secret, and carries a self-check
enumerating every public type. That self-check found an uncovered type immediately.

### Fixed - LOW: one depth constant, every parser

Requirement 4.4. `Redact::MAX_DEPTH` was pinned to `JsonLexeme::MAX_DEPTH` with a test asserting the
two were equal, but every `json_decode()` still passed its own literal `512` - so the constant could
change while the parsers silently kept the old bound. The invariant is now enforced against the code
by a sweep over every `json_decode()` call.

### Verified - requirements found open in sibling SDKs

- **2.6** (Node, JVM): no replacement semantics on any money path. `json_decode()` rejects malformed
  UTF-8 outright and no path passes `JSON_INVALID_UTF8_SUBSTITUTE`, verified across five invalid
  byte sequences and a lone-surrogate escape. Pinned with a test regardless.
- **6.3** (Python): already satisfied. `CURLOPT_ENCODING` is never set, so decompression is refused
  outright and the byte cap applies to wire bytes; a permanent test greps the source for it.
- **3.6**: every exposed `retryable` field agrees with the verdict at every nesting level, across an
  extended cross-product that now includes the inconclusive codes and terminal controls.
- **8.7**: PHP carries no NUL or other raw control bytes. A permanent guard now walks `src`, `tests`
  and `scripts` so it stays that way.

### Removed

- The vestigial `$warnedMissingIdempotencyKey` field. The once-per-process gate it controlled was
  removed in an earlier round but the field outlived it, which is an invitation to re-introduce the
  gate requirement 5.3 forbids.

## [0.9.0] - 2026-07-19

**Breaking.** A ninth independent review - the first complete one since round 8 - found ONE
CRITICAL, six highs, two mediums and three lows. Every protection below is verified NON-VACUOUS by
`scripts/non-vacuity.php`: **81/81 mutations caught** (up from 68/68). The golden webhook vector
still signs byte-for-byte identically.

The theme of this round is COMPOSITION. Every previous round found rules that were wrong. This one
found rules that were each right and wrong *together*:

> `Redact` rewrote an echoed credential to `[redacted]`, which is correct sanitisation.
> `Semantics::hasReceipt()` accepted any non-blank string as an M-Pesa receipt, which was locally
> defensible. Composed, **redacting a credential turned it into proof of payment**: `[redacted]` is
> non-blank, so it passed as a valid receipt, and a `status: "success"` record with no result code
> returned `paid = true`. The same held for a `payment.success` webhook.

Neither component was wrong on its own. They disagreed about what the placeholder MEANS, and the
money path sat in the gap.

### The rules that came out of it

- **Evidence needs a positive grammar, not a non-emptiness test.** `Semantics::RECEIPT_RE` is
  derived from the receipts this repository actually carries (`SFF6XYZ123` - ten bytes, uppercase
  letters and digits), not invented. Anything that does not match is not evidence.
- **A redaction marker satisfies no evidence, identifier or correlation check anywhere.** Audited
  across receipts, `paymentId`, `checkoutRequestId`, payment `id` and idempotency keys, with a
  permanent test that walks every one of them.
- **Refuse rather than redact on the money path.** A correctly-signed body echoing your webhook
  secret or API key is now REFUSED (`PaylodCredentialCompromiseError`), not sanitised and delivered.
  Redaction stays where it belongs: diagnostics. The other three SDKs made the same call.
- **Validate the form, then classify.** Twice more, in the description path.

### Fixed - Critical

- **Forged success through the redactor** (`Semantics.php`). See above. Receipt evidence is now
  validated against the derived grammar before anything else looks at it.

### Fixed - High

- **`500.*` description overload ran before code validation** (`DarajaCatalog.php`). `500.0`,
  `500.x` and `"500.001.1001\n"` became terminal failure evidence whenever the server-controlled
  description contained a phrase like "insufficient funds". The code's form is now settled first,
  and the overload branch is pinned to the exact documented code `500.001.1001`.
- **An unknown code was terminal failure evidence** (`DarajaCatalog.php`). Every canonically shaped
  positive integer classified as `failed`, catalogued or not - so `87654` made a claimed failure
  terminal and let a `payment.failed` webhook through as settled, while the decoder called the same
  code unprovable. Terminal now requires a catalog entry; an uncatalogued code is the new `unknown`
  outcome, mapping to `Semantics::EVIDENCE_UNKNOWN` with its own five table rows, all
  INDETERMINATE. **The verdict table is now 5 claims x 6 evidence kinds = 30 cells**, still with no
  default arm.
- **The webhook allowlist checked names but not TYPES** (`Webhook.php`). `applicationId:
  {"retryable":true}` and `amount: {"decoded":{"retryable":true}}` carried payload-supplied retry
  conclusions past the allowlist. Every allowlisted field now has a declared scalar type, and a
  wrong-typed one refuses the event rather than being pruned.
- **The client webhook wrappers leaked the raw body into traces** (`Paylod.php`). Both wrappers now
  mark the raw body and signature header `#[\SensitiveParameter]`, and `parseWebhook()` re-applies
  the client's exact credentials.
- **`decodeError()` did not redact** (`DarajaCatalog.php`, `Paylod.php`). An unknown code's raw
  `resultDesc` was copied into `cause` verbatim and reached `json_encode`, logs and dumps. Now
  shape-scrubbed in the catalog and exact-scrubbed on the client, with both raw descriptions marked
  sensitive.
- **A malformed 202 lost its payment id** (`Paylod.php`). Failure handling attached only the
  idempotency key, so an acknowledgement carrying a perfectly usable `paymentId` threw with
  `$exception->paymentId` null - on the exact path whose message says "go and read the payment".
  The id is now captured inside the validator, before validation can throw and before `$ack` is
  assigned, so an interrupt has the same coverage.

### Fixed - Medium

- **`PaymentOutcome` had an implicit fallback** - every verdict not matched by the first three
  branches rendered as terminal failure. Now an exhaustive `match` over all four verdicts with no
  default arm.
- **`Simulator::outcome()` and `pay()` bound no failure context** - a settlement failure after the
  payment was acknowledged returned an exception with both identifiers null. Both are now bound;
  `Simulator::collect()` also returns its effective idempotency key, as `Paylod::collect()` does.

### Fixed - Low

- The "unknown code is indeterminate" test asserted only decoder category and retryability; it now
  asserts the classifier, the semantic verdict, the rendered outcome and the webhook refusal.
- The scanner/parser divergence test never reached the fail-closed branch. A test-only scanner depth
  seam makes the divergence reachable, and the branch is now mutation-tested.
- **The non-vacuity harness is part of `composer test`.** While it was a separate command, an
  ordinary test run could report success without ever exercising it.

### Found by the new adversarial sweep

A permanent sweep constructs every public object and exception from a hostile response echoing both
credentials in every string field at several depths, then asserts neither appears in any output.
The sibling SDKs added the same net; Python's found six unreported leaks the moment it existed and
the JVM's found one. This one found two that nothing had reported:

- `DarajaCatalog::decode()` copied an unrecognised raw `ResultCode` lexeme into the public `code`
  field, reaching `json_encode`, logs and dumps through the OFFLINE decoder.
- `Judgement::$claimed` was a verbatim copy of the server's `status` string.

Both are now shape-scrubbed and bounded.

### Also

- **Redaction depth is pinned to parse depth.** `Redact::MAX_DEPTH` was 12 while every parse in the
  SDK uses 512, so a secret nested past depth 12 of a signed body was parsed in and then walked past
  by the scrubber. Node (8 vs 64), Python (12 vs 64) and JVM (8 vs 64) all carried the same drift.
  A test asserts the invariant.

## [0.8.0] - 2026-07-19

**Breaking.** An eighth independent review found ONE CRITICAL - a forged-success bypass that
defeated the round-6 impostor-zero guard by attacking its assumption rather than its logic - plus
three highs, five mediums and a low. Every protection below is verified NON-VACUOUS by
`scripts/non-vacuity.php`: **68/68 mutations caught** (up from 48/48). The golden webhook vector
(`whsec_golden_vector_v1` -> `3afe38e4...2c2eb7`) still signs byte-for-byte identically.

The theme of this round is ASSUMPTIONS ABOUT INPUT. The raw result-code guard was correct about
what a non-canonical zero looks like and wrong about how a JSON key can be spelled. The webhook
reconstruction removed the bad fields it knew the names of and forwarded everything else. Both are
the same mistake: a rule written against the shapes the author imagined, on input an attacker
chooses.

### Security - CRITICAL

- **The raw result-code guard is now a JSON PARSER, not a regex.** It matched the literal bytes
  `"resultCode"`. JSON permits `\uXXXX` escapes in member names, so the identical key spelled
  `"result\u0043ode"` bypassed the scan entirely, decoded to the PHP integer `0`, and was accepted
  as PAID on **both** the status path and the signed-webhook path - a forged success, reachable by
  anyone who could shape a response body. `Support\JsonLexeme` now walks the document as an
  incremental scanner: it DECODES every member name and associates it with the ORIGINAL bytes of
  the value beside it, at every nesting depth, for every duplicate key. When the scan cannot read a
  body that `json_decode()` can, the body is REFUSED rather than waved through.

### Breaking

- **`Webhook::verify()` rebuilds the event from an exact ALLOWLIST**, replacing the previous
  strip-four-known-names approach. Arbitrary root and `data` fields no longer survive verification.
  This closes nested retryability: `data.details.retryable = true` and `data.extra.deep.retryable
  = true` reached handlers verbatim on events the SDK had just judged NON-retryable. An unknown
  event type is now represented MINIMALLY - the envelope plus the scalar members of `data` - so
  forward compatibility cannot be a channel for a claim this version cannot check.
- **`Webhook::verify()` refuses a non-string payload.** The byte ceiling was checked on
  `strlen((string) $payload)`, i.e. AFTER a `\Stringable`/PSR-7 stream had been materialised in
  full - on an unauthenticated endpoint, where that read IS the denial of service. Pass the raw
  body string your framework already read under its own limit.
- **`Simulator::collect()` requires an idempotency key**, like production. It silently generated
  one, which made the surface developers use to prove "this cannot charge twice" the one surface on
  which it could. Pass `idempotencyKey`, or `unsafeGeneratedIdempotencyKey => true` to opt in and be
  warned.

### Security

- Malformed-2xx diagnostics run the COMPLETE message through the redactor. Several branches quoted
  server-controlled values (`status` via `json_encode`, a mismatched id) verbatim, so a gateway
  echoing the bearer token put a live key into the exception message and the application's log.
- The decoded webhook event is redacted with the EXACT supplied secret, not only credential SHAPES.
- Every webhook payload parameter is `#[\SensitiveParameter]` at every public and private wrapper.
- `sanitizedCause()` marks both parameters sensitive. It dropped the credential-bearing original
  from the `previous` chain and then handed it straight back through the surrogate's OWN structured
  trace, because PHP records call arguments in every frame.
- The signature header is bounded in bytes and segments BEFORE it is exploded.
- `Simulator` acknowledgement `outcomes` are rebuilt from the closed five-value set. They were
  forwarded from the server unvalidated and unredacted into ordinary, logged output.
- `Phone::INPUT_RE` anchors with `\z`, not `$`, which accepted a trailing newline.

### Fixed

- The simulator runs the PRODUCTION collect rules: `Validate::collectAmount()` and
  `Validate::collectIdempotencyKey()` are now shared, so the 150,000 KES ceiling and the key
  requirement apply to both dispatch surfaces from one implementation.
- `Simulator::pay()` validates its outcome BEFORE `collect()` creates a payment. A typo'd outcome
  left a stranded pending payment behind.
- A simulator dispatch failure carries the effective idempotency key, so a caller retries with the
  same one instead of minting a fresh key and double-charging.

### Testing

- The mutation harness treated ANY nonzero PHPUnit exit as CAUGHT, so a warning, a runtime error or
  a crashed selected test was indistinguishable from a protection working. A catch now requires a
  genuine assertion FAILURE with zero errors, risky, skipped or incomplete tests, and the
  pre-mutation run must be spotless. Enabling this immediately exposed three tests that ERRORED
  rather than failed under mutation, two stale anchors, and one vacuous assertion.
- Every `nv:<id>` tag in a test must correspond to a registered mutation case, so a protection
  cannot have a test but no proof that the test is load-bearing.
- The full `retryable` cross-product is covered at BOTH published levels. The dangerous cell - an
  INDETERMINATE record whose code is retryable in the catalog - had no test.
- The transport's refusal to request automatic decompression is pinned, so a response-size ceiling
  can never be applied to a body that expanded past it after arriving.

## [0.7.0] - 2026-07-18

**Breaking.** A sixth independent review found no criticals - the first round with none - but five
highs and four mediums, plus four test-quality defects that were masking real bugs. Every protection
below is verified NON-VACUOUS by `scripts/non-vacuity.php`: **48/48 mutations caught** (up from
32/32). The golden webhook vector (`whsec_golden_vector_v1` -> `3afe38e4...2c2eb7`) still signs
byte-for-byte identically.

The theme of this round is ORDERING. In every one of the money-correctness defects the check itself
was correct and a layer BELOW it had already converted the impostor into canonical form before the
check ran. A validator downstream of a normaliser does not validate its input; it validates the
normaliser's output.

### Breaking

- **`Webhook::verify()` now OVERWRITES every derived field** rather than returning the event
  unchanged. `data.decoded`, `data.retryable`, `data.customerMessage` and `data.category` are
  recomputed from the local `DarajaCatalog`, at the root and inside `data`, and a missing `decoded`
  block is synthesized rather than left absent. A signature proves ORIGIN; it does not make a
  CONCLUSION true. A signed `payment.failed` carrying result code 17 and a forged
  `decoded.retryable = true` previously passed every check and told the caller to charge again.
  Unknown event types have their derived fields stripped, since nothing can verify them.

- **`Webhook::verifySignature()` no longer returns an event-shaped value.** It returns
  `{signatureValid, actionable: false, unverifiedEvent}`. Two functions with the same return shape,
  one safe and one not, is a trap rather than an API. `isValidSignature()` is renamed
  `isValidSignatureOnlyNotActionable()`. Both are `@internal` - they exist to pin the cross-repo
  golden vector. Use `verify()` / `isValid()` for anything reaching a handler.

- **Redirect and off-origin detections now throw `PaylodCredentialCompromiseError`**, not
  `PaylodConnectionError`. It extends `PaylodException`, so `catch (PaylodException $e)` is
  unaffected, but the retry loop cannot swallow it.

- **A collect acknowledgement's identifiers must satisfy an identifier grammar.** A `paymentId` or
  `checkoutRequestId` that is oversized, malformed, or credential-shaped is a keyed INDETERMINATE
  error rather than a successful ack.

### Money correctness

- **Result codes are validated on their LEXEME, before anything can normalise them.**
  `DarajaCatalog::normalizeCode()` trimmed strings and stringified floats, so `" 0"` and the float
  `0.0` were both classified SUCCESS, and `" 1032"` was laundered into the real 1032 entry - a
  cancellation the catalog marks RETRYABLE, i.e. the SDK invited a second charge on a code it had
  itself rewritten. Ints render canonically, strings pass through VERBATIM, and floats, booleans and
  null have no lexeme at all - unreadable, which is `pending`, never success and never terminal.

- **Raw JSON numeric lexemes are checked before `json_decode()` destroys them.**
  `{"resultCode":-0}` decodes to the PHP integer `0` - byte-identical to a genuine settlement - so
  no post-parse check could tell them apart, and a `status: "success"` body carrying it was reported
  PAID. `Support\JsonLexeme` scans the raw bytes on both the money path and the webhook path and
  refuses the body. The scan is lexical, governs `resultCode` only, and fails closed; its limits are
  documented on the class.

- **A detected credential compromise is no longer retried.** These threw `PaylodConnectionError`,
  which the retry loop treats as a network blip, so a detected compromise was dispatched three times
  with the default `maxRetries` - replaying the bearer key to the attacker twice more, and on
  `/collect` posting the charge three times.

- **PCRE anchors are `\z`, not `$`.** `$` also matches before a trailing newline, so `"1032\n"`
  passed the canonical-code regex with no `trim()` involved. Fixed in `DarajaCatalog`, `Phone`, the
  webhook timestamp and digest checks, and the Laravel provider's lexical number check.

### Secret hygiene

- **Raw response bodies and validator closures are `#[\SensitiveParameter]`.** PHP records call
  arguments in every stack trace when `zend.exception_ignore_args=0`, so a reflected bearer token
  was scrubbed from the message and the attached body while sitting verbatim in `getTrace()`.
  Marking `Validate::collectAck()` was not sufficient - the validator CLOSURE is its own frame,
  invoked with the raw body as its argument.

- **`baseUrl` is sensitive and redacted in configuration errors.** `https://mp_live_key@paylod.dev/`
  is refused for carrying userinfo - correctly - but the message interpolated the URL verbatim, so
  the diagnostic printed the live key into the caller's log. A check that leaks the secret it
  detects is not a protection.

- **Identifier fields cannot carry a credential.** A 202 returned `paymentId` / `checkoutRequestId`
  with no shape check, so a server echoing the bearer token into either put it into commonly logged
  output through the SUCCESS path, where nothing redacts.

### Robustness

- **Response headers have an aggregate ceiling** (`MAX_HEADER_BYTES` 256 KiB, `MAX_HEADER_COUNT`
  200). libcurl caps each individual header at 100 KiB and hands them over one at a time, which
  limits nothing about how many arrive; every one was accumulated forever. Overflow raises the same
  keyed indeterminate error a body overflow does.

- **`config/paylod.php` no longer pre-casts `timeout_ms` / `max_retries`.** `(int) env(...)` ran
  before the provider's lexical checks, so `PAYLOD_TIMEOUT_MS=1.5` arrived as a well-formed `1` -
  the operator's value neither honoured nor rejected. For a timeout that matters: `(int) 0.5` is
  `0`, and `0` disables cURL's timeout entirely.

### Tests

Four test-quality defects, each of which was masking a real bug:

- `DarajaCatalogTest` declared the float `0.0` and the padded `"  0  "` SCHEMA-APPROVED, enforcing
  the opposite of the required rule. Both move to the impostor provider, along with booleans, with
  end-to-end assertions through `PaymentOutcome` and webhook verification.
- The decoded-webhook test built its expectation from the same catalog it asserted against, so it
  passed whether the block was re-derived or forwarded. It now supplies deliberately false
  conclusions and requires them to be overwritten.
- The redirect tests asserted only the exception; `MockHttpClient` repeats its last step, so the
  extra dispatches were invisible. They now assert exactly ONE dispatch.
- The stack-trace probe ignored a failed temp-file write, so `"Could not open input file"` satisfied
  its non-empty assertion and the probe passed without running. It now asserts the file, the
  subprocess exit status and the presence of real paylod frames, and inspects the COMPLETE
  structured trace rather than only `getTraceAsString()`. `phpunit failOnWarning` is now `true`.

The Laravel tests injected a config `Repository` directly and so never executed the shipped config
file - the layer where the defect lived. New tests load it for real with the environment set.

`MockHttpClient` gained a `raw` step so a test can supply response BYTES; `json_encode()` would
re-serialise and destroy the very lexemes several of these tests exist to exercise.

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

[Unreleased]: https://github.com/mosesmrima/paylod-php/compare/v0.10.1...HEAD
[0.10.1]: https://github.com/mosesmrima/paylod-php/compare/v0.10.0...v0.10.1
[0.10.0]: https://github.com/mosesmrima/paylod-php/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/mosesmrima/paylod-php/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/mosesmrima/paylod-php/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/mosesmrima/paylod-php/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/mosesmrima/paylod-php/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/mosesmrima/paylod-php/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/mosesmrima/paylod-php/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/mosesmrima/paylod-php/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/mosesmrima/paylod-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/mosesmrima/paylod-php/releases/tag/v0.1.0
