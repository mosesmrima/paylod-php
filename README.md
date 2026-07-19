# paylod/paylod

This package is the official PHP client for the **paylod API**. It collects M-Pesa payments without Daraja boilerplate. It includes **Laravel** support.

You send one call. paylod hosts the Daraja callback. paylod refreshes the access token. paylod decodes the result code. paylod then sends you a signed webhook when the payment settles.

**No backend. No fees. No custody.** paylod operates the M-Pesa integration, so you do not run a callback server. There are no per-transaction fees during free early access. The money does not go to paylod. The money settles into **your own** Daraja shortcode with **your own** credentials. You supply the Daraja credentials. paylod supplies the rest.

---

## Install

```bash
composer require paylod/paylod
```

This package requires **PHP 8.1+** with the `curl`, `json` and `hash` extensions. All three extensions are standard. No third-party HTTP client is necessary.

> **The package name is `paylod/paylod`.** The name matches the scoped npm name `@paylod/node`. The vendor name and the package name are the same word. This SDK is the PHP client for the product, so the name `paylod/sdk` was not used.

## Quickstart

> Call this SDK from a server only. Your `PAYLOD_API_KEY` can move money. Never ship the key in a browser bundle, a mobile application, or any other client.

> Pass an idempotency key on every collect call. Mint one key for each payment attempt. Duplicates of that attempt collapse into one STK push and one charge. A double-clicked Pay button, a refreshed tab, and a redelivered job are duplicates of one attempt. Do not use the order id or the product id as the key. A retry after a wrong PIN is a new attempt, and it needs a new key.

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

This example is the complete integration. `collectAndWait()` sends the STK push. It then polls the payment, and it returns an outcome that you can render.

The idempotency key is required. The SDK refuses a collect call without one, before the call leaves your process. For more information, read [Idempotency](#idempotency).

**One argument in, one renderable outcome out.** You pass an API key. You do not pass a base URL, a config object, or an access token. You get a `message` that the customer can read. You also get a `retryable` flag that controls your retry button. Your application does not need a result code table.

paylod decodes the M-Pesa result codes for you. Do not write a test such as `if ($code === 1032)` in your application.

---

## Laravel

Auto-discovery registers the service provider and the `Paylod` facade. To change the configuration, publish it first:

```bash
php artisan vendor:publish --tag=paylod-config
```

Set your key in `.env`.

```dotenv
PAYLOD_API_KEY=mp_live_xxxxxxxx
PAYLOD_WEBHOOK_SECRET=whsec_xxxxxxxx   # only if you consume webhooks
```

Then inject the client, or use the facade.

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

The constructor throws `Paylod\Exceptions\PaylodConfigError` immediately if it finds no key.

| Option | Type | Default |
| --- | --- | --- |
| `apiKey` | `string` | `PAYLOD_API_KEY` env |
| `baseUrl` | `string` | `PAYLOD_BASE_URL` env, else `https://paylod.dev/functions/v1`. Origin-allowlisted: only `https://paylod.dev` / `https://api.paylod.dev`, no userinfo, no non-443 port, no query/fragment, no raw IPs |
| `allowInsecureBaseUrl` | `bool` | `false` (test-only: permit a loopback `baseUrl`; never with an `mp_live_` key) |
| `webhookSecret` | `string` | `PAYLOD_WEBHOOK_SECRET` env |
| `timeoutMs` | `int` | `30000` (must be 1-600000 ms; `0` would disable the timeout entirely) |
| `maxRetries` | `int` | `2` (transient failures only: network, transient 5xx, 429; not 501/505/511) |
| `simulate` | `bool` | `false` (sandbox simulator; requires a `mp_test_` key) |
| `httpClient` | `Paylod\Http\HttpClient` | `CurlHttpClient`. **Test-only.** This option requires `allowCustomHttpClient => true`. The SDK refuses this option for `mp_live_` keys. A custom client receives your `Authorization` header on every request |
| `allowCustomHttpClient` | `bool` | `false` (the explicit opt-in that `httpClient` requires) |

### `collect(array $params): array`

This method sends the STK push. It returns as soon as the STK push is on the handset.

Pass an idempotency key on every collect call. Mint one key for each payment attempt. The idempotency key is required. The SDK refuses a collect call without one, before the call leaves your process.

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

The SDK validates `amount`, `phone` and the field lengths **locally**. Bad input throws `PaylodInvalidRequestError` before the SDK sends the request.

### `status(string $paymentId): array`

```php
$p = $paylod->status($ack['paymentId']);
// ['id' => ..., 'status' => 'pending'|'success'|'failed', 'mpesaReceipt' => ..., 'resultCode' => ..., 'resultDesc' => ...]
```

> Note the state names. This method returns **`success`**, not `paid`.

### `check(string $paymentId): PaymentOutcome`

This method does the same work as `status()`. It returns a decoded outcome that you can render. Use this method.

### `wait(string $paymentId, array $options = []): PaymentOutcome`

This method polls an existing payment until the payment settles. The options are `timeoutMs` (default 120000) and `onPoll` (a callable, called with each pending snapshot). The poll interval increases through 1s, 1s, 1.5s, 2s, 2.5s, 3s, 4s and 5s. The interval stops at 5s. Each interval has +/-20% jitter.

`wait()` uses the **classifier** to decide whether the payment settled. It does not use the raw `status` field. Daraja reports result code `4999` on a record that Daraja also marks `failed`. Result code `4999` means that the STK push is live on the handset. The customer did not enter the PIN yet. Therefore `wait()` continues to poll.

### `collectAndWait(array $params, array $options = []): PaymentOutcome`

This method does the work of `collect()` and then the work of `wait()`.

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

**Two rules apply to this shape:**

1. `retryable` means SAFE TO CHARGE AGAIN. It does not mean that the customer may press a button. `retryable = false` means that paylod cannot prove that no debit occurred. Result code `4999` and result code `500.001.1001` mean that the STK push is live on the handset. The customer did not enter the PIN yet. The payment is IN FLIGHT, not failed. A pending payment is never retryable. A second collect call sends a second STK push, and it can charge the customer twice.
2. A wrong PIN is an answer, not an exception. A cancellation, a wrong PIN, and a low balance come back as data, with `status` and a `message`. They do not raise an error.

**The SDK throws these exceptions:** `PaylodInvalidRequestError` for bad input. `PaylodConfigError` for a missing key. `PaylodApiError` for a non-2xx response. `PaylodApiError` carries `->status` and the methods `->isAuthError()`, `->isRateLimited()`, `->isIdempotencyConflict()`, `->isIdempotencyIndeterminate()`, `->isIdempotencyInProgress()` and `->isIdempotencyBodyConflict()`. `PaylodConnectionError` means that the network failed after the retries. `PaylodTimeoutError` means that the payment is still pending at the deadline.

A timeout is not a failed payment. The outcome is INDETERMINATE. The customer can still enter the PIN, and the payment can still succeed. Leave the order pending. The webhook settles the order.

### `decodeError(int|string|null $resultCode, ?string $rawDesc = null): array`

This method operates offline. It uses no network and no API call.

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

These strings are byte-identical to the strings that paylod puts in `event.data.decoded`. The same data is available from `Paylod\DarajaCatalog::decode(...)` and from `Paylod\DarajaCatalog::errorCatalog()`.

---

## Test your checkout without a phone

Most payment defects occur in the failure paths. The simulator removes the handset, and it changes nothing else. You get a real sandbox payment record, real Daraja result codes, and a real signed webhook. The settlement path is also real.

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

To test **your** code, do the two steps separately. The payment id is real. Therefore your poller, your webhook route and your user interface operate without a change.

```php
$created = $paylod->simulator->collect(['amount' => 250]);
$paylod->simulator->outcome($created['paymentId'], 'insufficient_funds');
$view = readCheckout($created['paymentId']); // your code, verbatim
```

You can also build the client with `['simulate' => true]`. `collect()` then creates a simulated payment, and it sends no STK push to a handset.

Every simulator call refuses an `mp_live_` key locally, before the call leaves your process. The SDK throws `PaylodSandboxOnlyError`. The option `['simulate' => true]` throws from the constructor for an `mp_live_` key.

---

## Webhooks

paylod sends a signed JSON body to your endpoint when a payment settles.

```
POST /your/webhook
x-webhook-signature: t=1700000000,v1=<hex hmac-sha256>
x-webhook-id: <event id>
x-webhook-event: payment.success
```

The signature is `HMAC-SHA256(secret, "${t}.${rawBody}")`. Verify the signature with a 300s replay tolerance and a constant-time compare.

> Verify the signature against the raw bytes. A re-serialised body does not reproduce the same bytes, and the signature check then fails. In Laravel, read `$request->getContent()` for the raw string. Do not read the parsed array.

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

paylod can deliver the same webhook more than once. Key your fulfilment on the signed `data.paymentId`, and make the fulfilment idempotent. Do not use the `x-webhook-id` header for this check. The header is unsigned, so an attacker can replay a body under a new header value.

The SDK exports `Paylod\Webhook::sign($rawBody, $secret, $timestamp)`. Use this function to build realistic fixtures in your own tests, without a network.

---

## Idempotency

**Read this section. This section shows you how to prevent a double charge.**

An idempotency key names **one payment attempt**. Pass an idempotency key on every collect call. Mint one key for each payment attempt. Duplicates of that attempt collapse into one STK push and one charge. A double-clicked Pay button, a refreshed tab, and a redelivered job are duplicates of one attempt. Do not use the order id or the product id as the key. A retry after a wrong PIN is a new attempt, and it needs a new key.

```php
$attempt = $db->attempts()->create(['order_id' => $order->id]); // a row per press of Pay
$paylod->collectAndWait(['amount' => $amount, 'phone' => $phone, 'idempotencyKey' => $attempt->id]);
```

**Key format:** printable ASCII (`0x20-0x7E`), 1 to 255 bytes. The key travels as an HTTP header, and header values are ASCII. The SDK refuses a key that contains an accented letter, a CJK character, or a pasted en dash. The SDK refuses that key locally. A re-encoded key is a **different** key, and a different key does not deduplicate. Use a UUID, or convert your order id to an ASCII slug.

| Key you pass | What happens |
| --- | --- |
| An id minted per **payment attempt** | Correct. Duplicates collapse. A new attempt is a new charge. |
| Your **order id** | Stable, but never fresh. A retry after a wrong PIN repeats the FAILED attempt. That order can never be paid. |
| A **product id**, used again for each purchase | Very dangerous. Every customer after the first customer repeats the first payment. |
| A fresh UUID **for each call** | The same result as no key. A double click makes two keys, two STK pushes and two charges. |

**A concurrent double click cannot cause a double charge.** paylod reserves the key before it calls Daraja. Therefore ten simultaneous requests with the same key produce one payment and one STK push.

**One condition makes the same key unsafe for a retry.** An interrupted request spends its idempotency key, and paylod answers `409` indeterminate (`$err->isIdempotencyIndeterminate()`). Read the payment status before you retry. Use `$paylod->check($paymentId)`. The `409` is a STOP signal, not a retry signal. paylod cannot prove that no debit occurred, so paylod refuses to repeat the request. If the payment settled, you are done. If nothing occurred, start a new attempt with a NEW key. For money, at-most-once is better than at-least-once.

The idempotency key is required. The SDK refuses a collect call without one, before the call leaves your process. `collect()` throws `PaylodInvalidRequestError`. Therefore no STK push can be on a handset when you see the exception.

This behaviour is deliberate, and it is a change in 0.6.0. Earlier versions minted a key for you, and they warned once for each process. A minted key is not idempotency. A minted key is a different value on every call, so it collapses nothing. A minted key protects an internal network retry inside one call only. If your application sends the same charge twice, the customer still pays twice. The guard was off by default, behind a warning that most production deployments never show.

Do not use this option in production. To make an unprotected charge in a scratch script, pass `'unsafeGeneratedIdempotencyKey' => true`. The SDK then mints a throwaway key, and it warns on every call. A throwaway key protects nothing, and the call can charge a customer twice.

---

## Testing your integration

The SDK sends every request through its OWN transport. The transport holds the API key. You pass a
method, a path and a body. You never see the credential, and you never build a header. For tests,
you can replace the low-level HTTP client under the transport. You must do this deliberately, and
you must use a sandbox key.

Read the next paragraph before you use this option. Your API key is a bearer credential. Whoever
receives the key can move money. A custom client receives the key on every request. A custom client
also decides for itself whether to follow a redirect. If the custom client follows a cross-origin
`302`, it sends your key to another host before the SDK can refuse. For this reason the custom
client is a gated test seam, not a general extension point. You can never combine it with an
`mp_live_` key. The client enforces this rule, and the transport enforces the rule again.

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

You can wrap any PSR-18 client in an `HttpClient` with a few lines of code. Use this method to send
test traffic through your existing HTTP stack. The wrapped client must not follow redirects.

---

## Security

[SECURITY.md](SECURITY.md) states the threat model. It states what this SDK defends against. It also
states what this SDK does not defend against. The closure-based secret storage resists `var_dump`,
`print_r`, `var_export` and serialization. An attacker who can already run code in your process can
still recover the key. That attacker casts the client with `(array)` and calls the closures in the
result. Therefore this storage is a defence against accidental disclosure. It is not a security
boundary. No in-process client library can defend against hostile in-process code.

Report a suspected vulnerability to **security@paylod.dev**. Do not use a public issue.

---

## License

MIT. See [LICENSE](LICENSE).
