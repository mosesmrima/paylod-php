# paylod/paylod

The official PHP client for the **paylod API** - M-Pesa collections without the Daraja boilerplate, with first-class **Laravel** support.

You send one call. paylod hosts the Daraja callback, refreshes the OAuth token, decodes the result code, and POSTs you a signed webhook when the money lands.

**No backend. No fees. No custody.** paylod runs the M-Pesa plumbing so you don't stand up a callback server; there are no per-transaction fees during free early access; and the money never touches paylod - it settles into **your own** Daraja shortcode with **your own** credentials. You bring the Daraja creds, paylod brings everything around them.

---

## Install

```bash
composer require paylod/paylod
```

Requires **PHP 8.1+** with the `curl`, `json` and `hash` extensions (all standard). No third-party HTTP client required.

> **Why `paylod/paylod`?** The package name mirrors the scoped npm name `@paylod/node` - one obvious, memorable vendor/package pair. `paylod/sdk` was the alternative, but the SDK is the product's PHP client, not a meta "sdk", so `paylod/paylod` reads better in `composer require` and in a Laravel app's `config/app.php`.

## Quickstart

```php
use Paylod\Paylod;

$paylod = new Paylod($_ENV['PAYLOD_API_KEY']);

$attempt = $db->attempts()->create(['order_id' => $order->id]); // a row per press of Pay

$outcome = $paylod->collectAndWait([
    'amount' => 100,
    'phone' => '0712345678',
    'idempotencyKey' => $attempt->id, // one key per payment ATTEMPT. A double-click cannot charge twice.
]);

if ($outcome->paid) {
    fulfil($outcome->receipt); // money moved
} else {
    echo $outcome->message;    // already decoded, already human
}
```

That's the whole integration. `collectAndWait()` sends the STK prompt, polls with a sane backoff, and hands you something you can **render**.

> **Pass `idempotencyKey`, and mint one per payment attempt.** Duplicates of that attempt - a double-clicked Pay button, a refreshed tab, a redelivered job - collapse into **one** prompt and **one** charge. Omit it and every call is a new charge. Do **not** key on the order or the product: that replays an old payment instead of making a new one. A retry after a wrong PIN is a new charge and needs a **new** key. See [Idempotency](#idempotency).

**One argument in, one renderable thing out.** You pass an API key - not a base URL, not a config object, not an OAuth token. You get back a `message` a customer can read and a `retryable` flag you can hang a button off. There is no result-code table in your app.

> **If you find yourself writing `if ($code === 1032)`, we've failed.** Decoding M-Pesa's result codes is our job, not yours.

### Server-side only - not browser-safe

Your `PAYLOD_API_KEY` can move money. Call this SDK from a server. Never ship the key to a browser.

---

## Laravel

Auto-discovery registers the service provider and the `Paylod` facade. Publish the config if you want to tweak it:

```bash
php artisan vendor:publish --tag=paylod-config
```

Set your key in `.env`:

```dotenv
PAYLOD_API_KEY=mp_live_xxxxxxxx
PAYLOD_WEBHOOK_SECRET=whsec_xxxxxxxx   # only if you consume webhooks
```

Then inject the client, or use the facade:

```php
use Paylod\Paylod;
use Paylod\Laravel\Facades\Paylod as PaylodFacade;

// Constructor injection - resolved as a singleton from config('paylod.*')
public function pay(Paylod $paylod)
{
    $outcome = $paylod->collectAndWait(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => $attemptId]);
    // ...
}

// Or the facade
$outcome = PaylodFacade::collectAndWait(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => $attemptId]);
```

`config/paylod.php` reads `PAYLOD_API_KEY`, `PAYLOD_BASE_URL`, `PAYLOD_WEBHOOK_SECRET`, `PAYLOD_TIMEOUT_MS`, `PAYLOD_MAX_RETRIES`, and `PAYLOD_SIMULATE` from the environment.

---

## API

### `new Paylod(?string $apiKey = null, array $options = [])`

```php
new Paylod($_ENV['PAYLOD_API_KEY']);          // the normal way
new Paylod();                                 // reads PAYLOD_API_KEY from the environment
new Paylod($key, ['timeoutMs' => 10000]);     // with an escape hatch
new Paylod(['apiKey' => $key]);               // everything-in-one-array form, if you prefer
```

Throws `Paylod\Exceptions\PaylodConfigError` immediately if there is no key anywhere.

| Option | Type | Default |
| --- | --- | --- |
| `apiKey` | `string` | `PAYLOD_API_KEY` env |
| `baseUrl` | `string` | `PAYLOD_BASE_URL` env, else `https://paylod.dev/functions/v1`. Origin-allowlisted: only `https://paylod.dev` / `https://api.paylod.dev`, no userinfo, no non-443 port, no query/fragment, no raw IPs |
| `allowInsecureBaseUrl` | `bool` | `false` (test-only: permit a loopback `baseUrl`; never with an `mp_live_` key) |
| `webhookSecret` | `string` | `PAYLOD_WEBHOOK_SECRET` env |
| `timeoutMs` | `int` | `30000` (must be 1-600000 ms; `0` would disable the timeout entirely) |
| `maxRetries` | `int` | `2` (transient failures only: network, transient 5xx, 429; not 501/505/511) |
| `simulate` | `bool` | `false` (sandbox simulator; requires a `mp_test_` key) |
| `httpClient` | `Paylod\Http\HttpClient` | `CurlHttpClient`. **Test-only.** Requires `allowCustomHttpClient => true` and is refused for `mp_live_` keys - a custom client receives your `Authorization` header on every request |
| `allowCustomHttpClient` | `bool` | `false` (the explicit opt-in that `httpClient` requires) |

### `collect(array $params): array`

Fire the STK push and return as soon as the prompt is on the phone.

```php
$ack = $paylod->collect([
    'amount' => 100,                 // positive INTEGER KES, <= 150000 (M-Pesa rejects decimals)
    'phone' => '0712345678',         // any Kenyan format
    'idempotencyKey' => $attempt->id, // PASS THIS. One key per payment ATTEMPT.
    'accountReference' => 'order-42', // optional, <= 12 chars - your correlation id (a LABEL, not a lock)
    'description' => 'Coffee',        // optional, <= 64 chars - shown on the prompt
    'metadata' => ['orderId' => '42'],// optional, stored - NOT returned on /status or the webhook
]);
// ['paymentId' => ..., 'status' => 'pending', 'checkoutRequestId' => ..., 'idempotencyKey' => ...]
```

`amount`, `phone` and the field lengths are validated **locally** - bad input throws `PaylodInvalidRequestError` before a byte hits the network.

### `status(string $paymentId): array`

```php
$p = $paylod->status($ack['paymentId']);
// ['id' => ..., 'status' => 'pending'|'success'|'failed', 'mpesaReceipt' => ..., 'resultCode' => ..., 'resultDesc' => ...]
```

> Note the states: **`success`**, not `paid`.

### `check(string $paymentId): PaymentOutcome`

`status()`, but already decoded and renderable. This is the one you want.

### `wait(string $paymentId, array $options = []): PaymentOutcome`

Poll an existing payment until it settles. Options: `timeoutMs` (default 120000), `onPoll` (callable, called with each pending snapshot). Polling ramps 1s -> 1s -> 1.5s -> 2s -> 2.5s -> 3s -> 4s -> 5s (capped), each with +/-20% jitter.

`wait()` decides "has it settled?" using the **classifier**, not the raw `status` field. Daraja reports code `4999` on a row it also marks `failed`, but `4999` means *"the prompt is live and the customer hasn't typed their PIN yet"* - so `wait()` keeps polling.

### `collectAndWait(array $params, array $options = []): PaymentOutcome`

`collect()` + `wait()`.

### The outcome: one renderable shape

`Paylod\PaymentOutcome` is a readonly object:

```php
$outcome->status;    // "succeeded" | "pending" | "cancelled" | "failed"
$outcome->message;   // customer-facing, already decoded. RENDER THIS.
$outcome->retryable; // SAFE TO CHARGE AGAIN. Gate your retry button on this.
$outcome->paid;      // the one branch a backend needs: if ($paid) fulfil()
$outcome->receipt;   // M-Pesa confirmation code; non-null exactly when $paid
$outcome->code;      // raw ResultCode as string, or null - for logs
$outcome->detail;    // decoded cause/fix/category array, or null - for logs
$outcome->payment;   // the raw payment record
```

**Two invariants worth internalising:**

1. **`retryable` means SAFE TO CHARGE AGAIN.** A `pending` payment is **never** retryable: codes `4999` / `500.001.1001` mean the STK prompt is live and the customer simply hasn't entered their PIN yet. Retrying pushes a **second prompt** and can double-charge them.
2. **A wrong PIN is not an exception - it's an answer.** Cancellations, wrong PINs and low balances come back as data (`status: "failed"`, with a `message`), not as a thrown error.

**What throws:** `PaylodInvalidRequestError` (bad input), `PaylodConfigError` (no key), `PaylodApiError` (non-2xx; carries `->status` and `->isAuthError()` / `->isRateLimited()` / `->isIdempotencyConflict()` / `->isIdempotencyIndeterminate()` / `->isIdempotencyInProgress()` / `->isIdempotencyBodyConflict()`), `PaylodConnectionError` (network failed after retries), `PaylodTimeoutError` (still pending at the deadline - **not** a failure; leave the order pending and let the webhook settle it).

### `decodeError(int|string|null $resultCode, ?string $rawDesc = null): array`

Offline. No network, no API call.

```php
$paylod->decodeError(1032);
// [
//   'code' => '1032',
//   'title' => 'Payment cancelled by the customer',
//   'cause' => 'The customer received the STK prompt but pressed Cancel...',
//   'fix' => 'Nothing is wrong with your setup - offer a clear retry...',
//   'category' => 'customer', // customer | balance | limit | credentials | network | mpesa_system | pending | success
//   'retryable' => true,
//   'customerMessage' => 'Payment cancelled - you can try again whenever you\'re ready.',
// ]
```

The strings are byte-identical to the ones paylod puts in `event.data.decoded`. Also available standalone via `Paylod\DarajaCatalog::decode(...)` and `Paylod\DarajaCatalog::errorCatalog()`.

---

## Test your checkout without a phone

Your failure paths are where payment bugs live. The simulator removes the handset - and nothing else. A real sandbox payment row, the real Daraja result codes, the real settlement path, a real signed webhook. Only the phone is fiction.

```php
$paylod = new Paylod($_ENV['PAYLOD_TEST_KEY']); // mp_test_... key

['outcome' => $outcome] = $paylod->simulator->pay(['outcome' => 'wrong_pin']);
$outcome->status;    // "failed"
$outcome->message;   // "That M-Pesa PIN was incorrect. Please try again and enter the right PIN."
$outcome->retryable; // true - no money moved, so a fresh charge is safe
```

| `outcome` | `status` | Result code |
| --- | --- | --- |
| `approve` | `succeeded` | `0` |
| `wrong_pin` | `failed` | `2001` |
| `insufficient_funds` | `failed` | `1` |
| `user_cancelled` | `cancelled` | `1032` |
| `timeout` | `failed` | `1037` |

To exercise *your* code, split it in two - the payment id is real, so your poller, webhook route and UI run unchanged:

```php
$created = $paylod->simulator->collect(['amount' => 250]);
$paylod->simulator->outcome($created['paymentId'], 'insufficient_funds');
$view = readCheckout($created['paymentId']); // your code, verbatim
```

Or build the client with `['simulate' => true]` so `collect()` itself creates a simulated payment instead of ringing a phone. **Sandbox only, structurally:** every simulator call refuses a `mp_live_` key locally (`PaylodSandboxOnlyError`), and `['simulate' => true]` throws from the constructor.

---

## Webhooks

paylod POSTs a signed JSON body to your endpoint when a payment settles:

```
POST /your/webhook
x-webhook-signature: t=1700000000,v1=<hex hmac-sha256>
x-webhook-id: <event id>
x-webhook-event: payment.success
```

The signature is `HMAC-SHA256(secret, "${t}.${rawBody}")`. Verify it with a 300s replay tolerance and a constant-time compare:

```php
// Boolean form - matches verifyWebhook($rawBody, $signatureHeader, $secret)
if (! $paylod->verifyWebhook($rawBody, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null, $secret)) {
    http_response_code(400);
    exit;
}

// Or get the decoded, typed event (throws PaylodSignatureVerificationError on failure)
$event = $paylod->parseWebhook($rawBody, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null);
if ($event['type'] === 'payment.success') {
    fulfil($event['data']['paymentId'], $event['data']['mpesaReceipt']);
}
```

> **The raw body is load-bearing.** Re-serialising a parsed body does not reliably reproduce the same bytes, so it will fail verification. In Laravel, read `$request->getContent()` (the raw string), never the parsed array.

**Deliveries can repeat.** Dedup your fulfilment on the **signed** `data.paymentId` and make it idempotent. Never dedup on the unsigned `x-webhook-id` header - it is replayable and not covered by the signature.

`Paylod\Webhook::sign($rawBody, $secret, $timestamp)` is exported so you can build realistic fixtures in your own tests without a network.

---

## Idempotency

**This is the section that stops you charging a customer twice. Read it.**

An idempotency key names **one payment attempt**. Duplicate deliveries of that one attempt - a double-click, a refreshed tab, a job-queue redelivery, an internal network retry - collapse into a single charge.

```php
$attempt = $db->attempts()->create(['order_id' => $order->id]); // a row per press of Pay
$paylod->collectAndWait(['amount' => $amount, 'phone' => $phone, 'idempotencyKey' => $attempt->id]);
```

**Key format:** printable ASCII (`0x20-0x7E`), 1-255 bytes. The key travels as an HTTP header, and header values are ASCII on the wire - a key containing an accented letter, a CJK character or a pasted en dash is rejected locally rather than silently re-encoded into a *different* key that no longer deduplicates. Use a UUID, or slug your order id to ASCII.

| Key you pass | What happens |
| --- | --- |
| An id minted per **payment attempt** | Correct. Duplicates collapse; a new attempt is a new charge. |
| Your **order id** | Stable, but never fresh. A retry after a wrong PIN replays the FAILED attempt - that order can never be paid. |
| A **product id** (reused across purchases) | Catastrophic. Every customer after the first replays the first-ever payment. |
| A fresh UUID **per call** | Equivalent to no key: a double-click is two keys, two prompts, two charges. |

**A concurrent double-click cannot double-charge**, unconditionally: the key is reserved before Daraja is called, so ten simultaneous requests with the same key produce one payment and one STK push.

**The one case where the same key is not a safe retry:** if an earlier request under that key died mid-flight against Daraja, the key is *spent*. paylod returns a `409` **indeterminate** (`$err->isIdempotencyIndeterminate()`). That is a **stop** signal, not a retry signal - read the payment status (`$paylod->check($paymentId)`), and only if nothing happened start a new attempt with a **new** key. For money, at-most-once beats at-least-once.

**If you omit the key**, the SDK generates a fresh one per call and returns it on the ack (`$ack['idempotencyKey']`) - persist it. That protects an internal network retry, but does nothing about your application sending the same logical charge twice. The SDK emits a one-time PHP warning when you omit it.

---

## Testing your integration

The SDK dispatches through its OWN transport, which holds the API key: you pass a method, a path
and a body, and never see the credential or build a header. For tests you can replace the low-level
byte mover underneath it - but only deliberately, and only with a sandbox key:

```php
use Paylod\Paylod;
use Paylod\Http\HttpClient;

$fake = new class implements HttpClient {
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        // POST /collect answers 202 Accepted with the literal status "pending". Anything else is
        // rejected as INDETERMINATE - a 200 here is not a dispatched charge.
        return ['status' => 202, 'headers' => [], 'body' => json_encode([
            'paymentId' => 'pay_test', 'status' => 'pending', 'checkoutRequestId' => 'ws_1',
        ])];
    }
};

$paylod = new Paylod('mp_test_x', [
    'httpClient' => $fake,
    'allowCustomHttpClient' => true,   // required; refused outright for mp_live_ keys
]);
```

**Why the opt-in.** Your API key is a bearer credential: whoever receives it can move money. A
custom client gets it on every request and decides for itself whether to follow a redirect - so a
cross-origin `302` it follows would replay your key to another host before the SDK could object.
That is why this is a gated test seam rather than a general extension point, and why it can never
be combined with an `mp_live_` key. The rule is enforced by the client and again inside the
transport.

Any PSR-18 client can be wrapped in an `HttpClient` in a few lines if you prefer to route through
your existing HTTP stack in tests. It must not follow redirects.

---

## License

MIT. See [LICENSE](LICENSE).
