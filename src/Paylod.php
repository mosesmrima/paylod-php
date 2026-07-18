<?php

declare(strict_types=1);

namespace Paylod;

use Closure;
use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodConfigError;
use Paylod\Exceptions\PaylodConnectionError;
use Paylod\Exceptions\PaylodInvalidRequestError;
use Paylod\Exceptions\PaylodTimeoutError;
use Paylod\Http\HttpClient;
use Paylod\Http\Transport;
use Paylod\Support\Redact;
use Paylod\Support\Uuid;
use Paylod\Support\Validate;

/**
 * The paylod API client.
 *
 * Construction takes an API key and nothing else that matters. The base URL is the same for every
 * customer, so it is baked in; there is no config to assemble and no OAuth token to fetch.
 *
 * ```php
 * $paylod = new Paylod($_ENV['PAYLOD_API_KEY']);
 * $outcome = $paylod->collectAndWait(['amount' => 100, 'phone' => '0712345678']);
 * if ($outcome->paid) { fulfil($outcome->receipt); }
 * else                { echo $outcome->message; }   // already decoded, already human
 * ```
 */
final class Paylod
{
    /**
     * The base URL - identical for every paylod customer, so it is baked in.
     *
     * (Maintainer note: the docs advertise https://api.paylod.dev/v1, which does NOT route - it
     * 307s to /signin. Do not "fix" this constant to that host until it actually routes.)
     */
    public const DEFAULT_BASE_URL = 'https://paylod.dev/functions/v1';

    private const DEFAULT_TIMEOUT_MS = 30000;
    private const DEFAULT_MAX_RETRIES = 2;
    private const DEFAULT_WAIT_TIMEOUT_MS = 120000;

    /** Ramp: quick first look, then ease off. Capped at 5s. Values in ms. */
    private const POLL_SCHEDULE_MS = [1000, 1000, 1500, 2000, 2500, 3000, 4000, 5000];

    private const MAX_AMOUNT = 150000;

    /**
     * The secrets, held behind CLOSURES rather than in string properties.
     *
     * __debugInfo() already covered print_r() and var_dump(). It does NOT cover var_export(), which
     * ignores the magic method entirely and walks the object's REAL properties - so
     * `var_export($paylod)` printed the live API key and the webhook secret verbatim, and
     * var_export() is exactly what config dumpers, cache warmers and "export this to a PHP file"
     * tooling call. A property that is not a string cannot be exported as one: var_export renders a
     * Closure as `\Closure::__set_state(array())`, with no access to its bound scope.
     *
     * @var Closure():string
     */
    private Closure $apiKey;

    /** @var Closure():?string */
    private Closure $webhookSecret;

    /** The masked prefix of the key, safe to print. Precomputed so no debug path touches the real one. */
    private ?string $apiKeyMasked;

    /** The masked prefix of the webhook secret, safe to print. */
    private ?string $webhookSecretMasked;

    private string $baseUrl;
    private int $timeoutMs;
    private int $maxRetries;
    private bool $simulate;

    /**
     * The credentialed transport. NOT replaceable: the API key lives INSIDE it, and this class
     * passes it a method, a path and a body - never headers, never a URL, never the credential.
     */
    private Transport $transport;

    /** The sandbox simulator: drive a payment to any of the five outcomes with no phone. */
    public readonly Simulator $simulator;

    /** Warn at most once per process - a double-charge is a money bug, so it earns a loud warning. */
    private static bool $warnedMissingIdempotencyKey = false;

    /**
     * @param string|array<string,mixed>|null $apiKey Your mp_live_... / mp_test_... key. Omit it to
     *   read PAYLOD_API_KEY from the environment. Pass an options array to use the object form.
     *   Marked #[\SensitiveParameter] so the key is rendered as a placeholder in every stack trace
     *   this frame appears in - without it, any uncaught exception anywhere below the constructor
     *   prints a live money-moving key into the application log (PHP records call arguments in
     *   traces whenever zend.exception_ignore_args=0, which is the default in development).
     * @param array<string,mixed> $options Escape hatches: baseUrl, webhookSecret, timeoutMs,
     *   maxRetries, simulate, allowInsecureBaseUrl, and the TEST-ONLY pair httpClient +
     *   allowCustomHttpClient. Also sensitive: it carries webhookSecret (and apiKey, in the object
     *   form).
     */
    public function __construct(
        #[\SensitiveParameter] string|array|null $apiKey = null,
        #[\SensitiveParameter] array $options = [],
    ) {
        if (is_array($apiKey)) {
            $options = $apiKey;
            $apiKey = null;
        }

        $key = $apiKey
            ?? ($options['apiKey'] ?? null)
            ?? (getenv('PAYLOD_API_KEY') ?: null);

        if (!is_string($key) || trim($key) === '') {
            throw new PaylodConfigError(
                'No paylod API key. Pass one - new Paylod($_ENV["PAYLOD_API_KEY"]) - or set the '
                . 'PAYLOD_API_KEY environment variable. This key can move money: keep it on a server '
                . 'and never ship it to a browser.'
            );
        }
        $apiKeyValue = trim($key);
        $this->apiKey = static fn (): string => $apiKeyValue;
        $this->apiKeyMasked = Redact::mask($apiKeyValue);

        // Baked in. PAYLOD_BASE_URL / baseUrl remain as escape hatches for self-hosting and tests.
        $base = $options['baseUrl'] ?? (getenv('PAYLOD_BASE_URL') ?: null) ?? self::DEFAULT_BASE_URL;
        $this->baseUrl = rtrim((string) $base, '/');

        // Reject a plaintext / non-canonical origin BEFORE any key can leave the process. Loopback
        // HTTP is allowed only behind an explicit test-only flag, and never with a live key.
        Transport::assertSecureBaseUrl(
            $this->baseUrl,
            $apiKeyValue,
            ($options['allowInsecureBaseUrl'] ?? false) === true,
        );

        $secretValue = $options['webhookSecret'] ?? (getenv('PAYLOD_WEBHOOK_SECRET') ?: null);
        $secretValue = is_string($secretValue) ? $secretValue : null;
        $this->webhookSecret = static fn (): ?string => $secretValue;
        $this->webhookSecretMasked = Redact::mask($secretValue);

        // A zero/negative timeout would DISABLE cURL's timeout - reject it rather than ship a client
        // that can hang forever.
        $this->timeoutMs = self::assertPositiveTimeoutMs($options['timeoutMs'] ?? self::DEFAULT_TIMEOUT_MS, 'timeoutMs');
        $this->maxRetries = self::assertMaxRetries($options['maxRetries'] ?? self::DEFAULT_MAX_RETRIES);

        // ROOT 1. A custom HTTP client is a GATED TEST SEAM, not a general extension point: it
        // receives the Authorization header on every request, so it is refused unless the caller
        // explicitly opts in AND is using a sandbox key. Both rules are re-asserted inside Transport
        // so the transport holds the line on its own terms.
        $httpClient = self::assertCustomHttpClient($options, $apiKeyValue);

        $this->transport = new Transport(
            $this->apiKey,
            $this->baseUrl,
            fn (string $s): string => (string) $this->redact($s),
            $httpClient,
        );

        // Simulator mode is a TEST posture, so it is fenced off from production at CONSTRUCTION time.
        $this->simulate = ($options['simulate'] ?? false) === true;
        if ($this->simulate) {
            Simulator::assertSandboxKey($apiKeyValue, 'new Paylod(..., ["simulate" => true])');
        }

        $this->simulator = new Simulator(
            $this->apiKey,
            fn (array $opts): array => $this->request(
                (string) $opts['method'],
                (string) $opts['path'],
                $opts['body'] ?? null,
                $opts['idempotencyKey'] ?? null,
                null,
                $opts['validate'] ?? null,
            ),
        );
    }

    /**
     * The gate on the test-only HTTP client seam. Mirrors `allowInsecureBaseUrl`: an explicit
     * opt-in, and NEVER with a live key.
     *
     * @param array<string,mixed> $options
     */
    private static function assertCustomHttpClient(array $options, #[\SensitiveParameter] string $apiKey): ?HttpClient
    {
        if (array_key_exists('transport', $options)) {
            throw new PaylodConfigError(
                'The `transport` option was removed in 0.5.0. The API key now lives INSIDE the SDK\'s '
                . 'own transport, which builds its own headers and URL and refuses redirects, so an '
                . 'injectable transport can no longer receive the credential. For tests, pass '
                . "['httpClient' => \$client, 'allowCustomHttpClient' => true] with an mp_test_ key."
            );
        }

        $client = $options['httpClient'] ?? null;
        if ($client === null) {
            return null;
        }
        if (!$client instanceof HttpClient) {
            throw new PaylodConfigError('httpClient must implement ' . HttpClient::class . '.');
        }
        if (($options['allowCustomHttpClient'] ?? false) !== true) {
            throw new PaylodConfigError(
                'Passing `httpClient` also requires `allowCustomHttpClient => true`. A custom HTTP '
                . 'client receives your Authorization header - i.e. a bearer credential that can move '
                . 'money - on every request, and it controls whether a redirect is followed. That is '
                . 'a test seam and must be opted into deliberately.'
            );
        }
        if (str_starts_with($apiKey, 'mp_live_')) {
            throw new PaylodConfigError(
                '`httpClient` may never be used with an mp_live_ key, with or without '
                . '`allowCustomHttpClient`. The API key is a bearer credential and the client '
                . 'receives it on every request. Use an mp_test_ key for tests that need to stub the '
                . 'transport.'
            );
        }

        return $client;
    }

    // -- HTTP -----------------------------------------------------------------

    /**
     * A 409 is retried ONLY when it is explicitly the "same key still running" case. Every other 409
     * (body conflict, indeterminate) is a real answer and must NOT be retried.
     */
    private const IN_PROGRESS_409_RE = '/already in progress/i';

    /**
     * 5xx statuses that are NOT transient - retrying them will never help, and blindly re-POSTing a
     * charge on them is a double-charge risk. 501 (not implemented), 505 (HTTP version), 511
     * (network auth required) are configuration/protocol errors, not blips.
     *
     * @var array<int,true>
     */
    private const NON_TRANSIENT_5XX = [501 => true, 505 => true, 511 => true];

    /**
     * @param array<string,mixed>|null $body
     * @param ?int $deadlineMs absolute deadline (nowMs) for the WHOLE operation. Each in-flight
     *   request is capped to the remaining time, and every backoff / Retry-After sleep is clamped to
     *   it - so a wait() cannot overrun its timeoutMs by a full request timeout per poll.
     * @param ?\Closure $validate run against a 2xx parsed body before it is returned. Throw here to
     *   reject a malformed success (e.g. a 200 with no payment id) instead of returning an empty shape.
     * @return array<string,mixed>
     */
    private function request(
        string $method,
        string $path,
        mixed $body = null,
        ?string $idempotencyKey = null,
        ?int $deadlineMs = null,
        ?\Closure $validate = null,
    ): array {
        $lastError = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                self::boundedSleepMs(self::jitter((int) (250 * (2 ** ($attempt - 1)))), $deadlineMs);
            }

            // Cap this request to whatever time the overall operation has left. A 30s per-request
            // timeout must never let a wait(['timeoutMs' => 5000]) run for 30s.
            $perRequestTimeout = $this->timeoutMs;
            $remaining = self::remaining($deadlineMs);
            if ($remaining !== null) {
                if ($remaining <= 0 && $attempt > 0) {
                    break; // out of retries' time - surface the last error / a timeout below
                }
                // The FIRST attempt always goes out, even against an already-expired deadline: an
                // operation that returns "failed" without having tried once is indistinguishable
                // from one that tried and lost, and on a money path those need different handling.
                // (A 0 here would DISABLE the transport timeout, hence the floor of 1ms.)
                $perRequestTimeout = max(1, min($perRequestTimeout, $remaining));
            }

            try {
                // THE DISPATCH. Method, path, body, idempotency key - no headers, no URL, no
                // credential. The transport builds all three from state this class cannot reach into,
                // pins the origin and refuses redirects on every call.
                $res = $this->transport->send($method, $path, $body, $idempotencyKey, $perRequestTimeout);
            } catch (PaylodConnectionError $e) {
                $lastError = $e;
                continue; // network blip -> retry
            }

            $text = $res['body'];
            $parsed = null;
            if ($text !== '') {
                $decoded = json_decode($text, true);
                $parsed = $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $text : $decoded;
            }

            $status = $res['status'];
            if ($status >= 200 && $status < 300) {
                $result = is_array($parsed) ? $parsed : [];
                // A malformed 2xx (e.g. no payment id) is INDETERMINATE, not a silent empty success.
                if ($validate !== null) {
                    $validate($result, $status);
                }

                return $result;
            }

            $message = (is_array($parsed) && isset($parsed['error']) && is_string($parsed['error']))
                ? $parsed['error']
                : "paylod responded {$status}";

            // A server error body is NOT trusted text: a misconfigured gateway that echoes the
            // request (or a 400 that quotes the Authorization header) would otherwise carry the live
            // API key straight into an exception message and the application's error log. Redact
            // BEFORE the error object exists, so no un-redacted copy is ever constructed.
            $message = $this->redact($message);
            $apiError = new PaylodApiError($message, $status, $this->redact($parsed), $idempotencyKey);

            // 429 / transient 5xx are retried. A 409 is retried ONLY when it is explicitly "same key
            // still in progress" - every other 409 (body conflict, indeterminate) is a real answer.
            $transient = $status === 429 || ($status >= 500 && !isset(self::NON_TRANSIENT_5XX[$status]));
            $inProgress = $status === 409 && preg_match(self::IN_PROGRESS_409_RE, $message) === 1;
            if ((!$transient && !$inProgress) || $attempt === $this->maxRetries) {
                throw $apiError;
            }

            $lastError = $apiError;
            // Honour Retry-After (delta-seconds OR HTTP-date), clamped to 10s and the operation deadline.
            $retryAfterMs = self::parseRetryAfterMs($res['headers']);
            if ($retryAfterMs !== null && $retryAfterMs > 0) {
                self::boundedSleepMs(min($retryAfterMs, 10000), $deadlineMs);
            }
        }

        throw $lastError ?? new PaylodConnectionError("Request to {$this->baseUrl}{$path} failed");
    }

    // -- Validation -----------------------------------------------------------

    /**
     * Validate + normalise locally so a bad amount or phone fails instantly, in your own stack
     * trace, instead of coming back as an opaque 422 a network round-trip later.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function buildCollectBody(array $params): array
    {
        $amount = $params['amount'] ?? null;
        if (!is_int($amount) && !is_float($amount)) {
            throw new PaylodInvalidRequestError('amount must be a number (whole KES).');
        }
        if (is_float($amount) && floor($amount) !== $amount) {
            throw new PaylodInvalidRequestError(
                "amount must be a whole number of KES - M-Pesa rejects decimals (got {$amount})."
            );
        }
        $amount = (int) $amount;
        if ($amount <= 0 || $amount > self::MAX_AMOUNT) {
            throw new PaylodInvalidRequestError(
                'amount must be between 1 and ' . self::MAX_AMOUNT . " KES (got {$amount})."
            );
        }
        if (isset($params['accountReference']) && strlen(trim((string) $params['accountReference'])) > 12) {
            throw new PaylodInvalidRequestError('accountReference must be 12 characters or fewer.');
        }
        if (isset($params['description']) && strlen(trim((string) $params['description'])) > 64) {
            throw new PaylodInvalidRequestError('description must be 64 characters or fewer.');
        }

        $body = [
            'amount' => $amount,
            'phone' => Phone::normalize((string) ($params['phone'] ?? '')),
        ];
        if (isset($params['accountReference'])) {
            $body['accountReference'] = $params['accountReference'];
        }
        if (isset($params['description'])) {
            $body['description'] = $params['description'];
        }
        if (isset($params['metadata'])) {
            $body['metadata'] = $params['metadata'];
        }

        return $body;
    }

    // -- Public API -----------------------------------------------------------

    /**
     * Send an STK Push. Resolves as soon as the prompt is on the customer's phone - the payment is
     * `pending`. Settle it with status(), wait(), or a webhook.
     *
     * `idempotencyKey` is REQUIRED. Mint ONE KEY PER PAYMENT ATTEMPT - one press of Pay, not an
     * order and never a product - and PERSIST it alongside the attempt before calling this method.
     * Duplicates of that attempt then collapse into one payment and one prompt.
     *
     * @param array{amount:int|float,phone:string,accountReference?:string,description?:string,metadata?:array<string,mixed>,idempotencyKey?:string,unsafeGeneratedIdempotencyKey?:bool} $params
     * @return array{paymentId:string,status:string,checkoutRequestId:string,idempotencyKey:string}
     */
    public function collect(array $params): array
    {
        $body = $this->buildCollectBody($params);
        $idempotencyKey = self::resolveIdempotencyKey($params);

        try {
            // Simulator mode: same call, same ack, no handset. The key was proven a sandbox key in
            // the constructor, so this branch cannot reach production.
            if ($this->simulate) {
                $simParams = [
                    'phone' => $params['phone'],
                    'amount' => (int) $params['amount'],
                    'idempotencyKey' => $idempotencyKey,
                ];
                // Forward the WHOLE body so the simulator fingerprints the same request production does.
                foreach (['accountReference', 'description', 'metadata'] as $field) {
                    if (isset($params[$field])) {
                        $simParams[$field] = $params[$field];
                    }
                }
                $created = $this->simulator->collect($simParams);

                return [
                    'paymentId' => $created['paymentId'],
                    'status' => 'pending',
                    'checkoutRequestId' => $created['checkoutRequestId'],
                    'idempotencyKey' => $idempotencyKey,
                ];
            }

            // A malformed 2xx is INDETERMINATE: the charge may have moved. The WHOLE ack schema is
            // checked - a blank checkoutRequestId or a missing/mistyped status is just as unusable as
            // a missing paymentId, and returning any of them as "success" hands the caller a shape it
            // will treat as a new payment and retry under a fresh key.
            $ack = $this->request('POST', '/collect', $body, $idempotencyKey, null, function (array $parsed, int $status) use ($idempotencyKey): void {
                Validate::collectAck($parsed, $status, $idempotencyKey, $this->redactor());
            });

            return [
                'paymentId' => (string) ($ack['paymentId'] ?? ''),
                'status' => (string) ($ack['status'] ?? 'pending'),
                'checkoutRequestId' => (string) ($ack['checkoutRequestId'] ?? ''),
                'idempotencyKey' => $idempotencyKey,
            ];
        } catch (\Throwable $e) {
            // Whatever went wrong (network, timeout, 5xx, malformed 2xx), the caller MUST be able to
            // recover the effective key and retry with the SAME one - a fresh key would double-charge.
            if ($e instanceof \Paylod\Exceptions\PaylodException) {
                $e->attachIdempotencyKey($idempotencyKey);
                throw $e;
            }

            // A NON-PAYLOD throwable (a stubbed HTTP client's own exception, a JsonException, a
            // TypeError from somewhere below) used to escape BARE. It carried no idempotency key and
            // no indeterminate classification, so the caller's natural reaction - retry the call -
            // minted a FRESH key and charged the customer a second time. The charge state at this
            // point is genuinely unknown: the request may have reached the API. Wrap it in an error
            // that says so and carries the key.
            $wrapped = new PaylodApiError(
                'collect() failed with an unexpected error: ' . $this->redact($e->getMessage())
                . ' - the charge state is INDETERMINATE, the STK prompt may already be on the phone. '
                . 'Read the payment with this idempotencyKey before starting any new attempt; do NOT '
                . 'mint a fresh key (that risks a second charge).',
                0,
                null,
                $idempotencyKey,
                true,
                self::sanitizedCause($e, $this->redactor()),
            );

            throw $wrapped;
        }
    }

    /**
     * Read a payment. GET /status/:id.
     *
     * @return array{id:string,status:string,mpesaReceipt:?string,resultCode:int|string|null,resultDesc:?string}
     */
    public function status(string $paymentId, ?int $deadlineMs = null): array
    {
        if ($paymentId === '') {
            throw new PaylodInvalidRequestError('paymentId is required.');
        }

        $p = $this->request('GET', '/status/' . rawurlencode($paymentId), null, null, $deadlineMs, function (array $parsed, int $status) use ($paymentId): void {
            // The FULL payment schema, and - law L1 - the BINDING check: the body must describe the
            // payment that was ASKED ABOUT. A response that answers a different question is not a
            // malformed response, it is a WRONG one, and no field-level shape check can find it.
            Validate::paymentBody($parsed, $status, $this->redactor(), $paymentId);
        });

        // REDACTED ON THE WAY OUT, not only on the error path.
        //
        // Redaction used to be applied to error bodies and exception messages only, on the
        // reasoning that a 2xx is "our own" data. It is not: it is bytes from the network, and the
        // same misconfigured gateway or debug-echo endpoint that quotes the Authorization header
        // into a 400 can quote it into a 200 - most plausibly into `resultDesc`, which is free text
        // the SDK hands straight to a caller who logs it, renders it, or puts it in a support
        // ticket. A live key escaping through the SUCCESS path is the same disclosure as one
        // escaping through the failure path. The exact-secret and credential-shape scrubbing is
        // therefore applied to every field returned here.
        return [
            'id' => (string) ($p['id'] ?? $paymentId),
            'status' => (string) $this->redact((string) ($p['status'] ?? 'pending')),
            'mpesaReceipt' => $this->redact($p['mpesaReceipt'] ?? null),
            'resultCode' => $this->redact($p['resultCode'] ?? null),
            'resultDesc' => $this->redact($p['resultDesc'] ?? null),
        ];
    }

    /**
     * Read a payment and return it already decoded and renderable. This is status() for people who
     * want to show a human what happened, which is almost everybody.
     */
    public function check(string $paymentId): PaymentOutcome
    {
        return PaymentOutcome::fromPayment($this->status($paymentId));
    }

    /**
     * Poll an existing payment until it settles, with a backoff ramp (1s -> 5s, jittered).
     *
     * @param array{timeoutMs?:int,onPoll?:callable} $options
     *
     * @throws PaylodTimeoutError if still pending at the deadline. That is deliberately NOT a
     *   `status: "failed"` outcome: we do not know what happened, and telling a merchant "failed"
     *   when the customer is mid-PIN loses real money.
     */
    public function wait(string $paymentId, array $options = []): PaymentOutcome
    {
        [$timeoutMs, $onPoll] = self::parseWaitOptions($options);
        $startedAt = self::nowMs();
        $deadline = $startedAt + $timeoutMs;

        $last = null;
        for ($attempt = 0; ; $attempt++) {
            // Propagate the wait's deadline into each poll so no single status read can hang past it.
            $payment = $this->status($paymentId, $deadline);
            $last = $payment;

            $outcome = PaymentOutcome::fromPayment($payment);
            if ($outcome->status !== 'pending') {
                return $outcome;
            }
            if ($onPoll !== null) {
                $onPoll($payment);
            }

            $delay = self::pollDelay($attempt);
            if (self::nowMs() + $delay >= $deadline) {
                break;
            }
            self::sleepMs($delay);
        }

        throw new PaylodTimeoutError($paymentId, $last ?? [], self::nowMs() - $startedAt);
    }

    /**
     * collect() + wait(). The one-liner most integrations actually want: ring the phone, wait for
     * the PIN, hand back something you can render.
     *
     * @param array{amount:int|float,phone:string,accountReference?:string,description?:string,metadata?:array<string,mixed>,idempotencyKey?:string} $params
     * @param array{timeoutMs?:int,onPoll?:callable} $options
     */
    public function collectAndWait(array $params, array $options = []): PaymentOutcome
    {
        // Validate the WAIT options BEFORE the charge is dispatched. Doing it inside wait() meant a
        // typo'd timeoutMs threw only AFTER the STK prompt was already on the customer's phone -
        // a validation error the caller reads as "nothing happened", when in fact a payment exists.
        self::parseWaitOptions($options);

        $ack = $this->collect($params);

        try {
            return $this->wait($ack['paymentId'], $options);
        } catch (\Throwable $e) {
            // EVERYTHING past the acknowledgement is caught here - a timeout, a transport failure, an
            // HTTP error, a malformed poll body. The payment EXISTS by this point, so an error that
            // does not carry the effective idempotency key is a double-charge waiting to happen: the
            // caller retries collectAndWait(), the SDK mints a FRESH key, and the customer is charged
            // twice for one order. The key (and the payment id) must ride out on the exception.
            if ($e instanceof \Paylod\Exceptions\PaylodException) {
                // OVERWRITE, never "attach". The best-effort attach* semantics preserved whatever
                // key or payment id the error already happened to carry - and an error thrown from
                // an onPoll callback can carry an UNRELATED one, from a different charge entirely.
                // The caller then read the wrong payment, concluded nothing had happened, and
                // re-charged under a fresh key. Past the acknowledgement there is exactly one
                // payment this error can be about, and the ack is what names it.
                $e->bindToAcknowledgedPayment($ack['idempotencyKey'], $ack['paymentId']);
                throw $e;
            }

            // A non-paylod throwable (an injected transport's own exception, a callback's error)
            // cannot carry the key, so it is WRAPPED in one that can rather than escaping bare.
            $wrapped = new PaylodApiError(
                'The payment was accepted but the wait failed: ' . $this->redact($e->getMessage())
                . ' - the payment EXISTS. Read it with the attached paymentId / idempotencyKey; do '
                . 'NOT retry with a fresh key (that risks a second charge).',
                0,
                null,
                $ack['idempotencyKey'],
                true,
                self::sanitizedCause($e, $this->redactor()),
            );
            $wrapped->bindToAcknowledgedPayment($ack['idempotencyKey'], $ack['paymentId']);

            throw $wrapped;
        }
    }

    /**
     * Decode an M-Pesa result code offline. No network, no API key needed at call time. The strings
     * are identical to the ones the API puts in event.data.decoded.
     *
     * @return array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string}
     */
    public function decodeError(int|string|null $resultCode, ?string $rawDesc = null): array
    {
        return DarajaCatalog::decode($resultCode, $rawDesc);
    }

    /**
     * Verify a raw webhook body + signature header. Returns true if it checks out, false otherwise.
     *
     * Matches the documented `verifyWebhook($rawBody, $signatureHeader, $secret)` surface. When
     * `$secret` is omitted, the client's configured webhook secret (webhookSecret option /
     * PAYLOD_WEBHOOK_SECRET) is used. Use {@see parseWebhook()} when you want the decoded event.
     */
    public function verifyWebhook(
        string $rawBody,
        ?string $signatureHeader,
        #[\SensitiveParameter] ?string $secret = null,
        int|float $toleranceSec = Webhook::DEFAULT_TOLERANCE_SEC,
    ): bool {
        return Webhook::isValid($rawBody, $signatureHeader, $secret ?? ($this->webhookSecret)() ?? '', $toleranceSec);
    }

    /**
     * Verify a raw webhook body + signature header and return the decoded, typed event array.
     * Throws {@see \Paylod\Exceptions\PaylodSignatureVerificationError} if it does not check out.
     *
     * @return array<string,mixed>
     */
    public function parseWebhook(
        string $rawBody,
        ?string $signatureHeader,
        #[\SensitiveParameter] ?string $secret = null,
        int|float $toleranceSec = Webhook::DEFAULT_TOLERANCE_SEC,
    ): array {
        return Webhook::verify($rawBody, $signatureHeader, $secret ?? ($this->webhookSecret)() ?? '', $toleranceSec);
    }

    // -- Internals ------------------------------------------------------------

    /**
     * What print_r() / var_dump() actually show for this object.
     *
     * PHP dumps PRIVATE properties too, so without this a single `print_r($paylod)` in a debug
     * branch - or a framework's exception page, or a queue worker logging its job payload - prints
     * the live API key and the webhook secret verbatim. Only masked prefixes are exposed here:
     * enough to tell "wrong environment" at a glance, never enough to use.
     *
     * NOTE: this method is NOT what protects var_export(). var_export() ignores __debugInfo()
     * entirely and walks the real properties, which is why the two secrets are held in closures
     * (see $apiKey / $webhookSecret) rather than in string properties. Both defences are needed:
     * this one for the dump functions, the closures for var_export().
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => $this->apiKeyMasked,
            'webhookSecret' => $this->webhookSecretMasked,
            'baseUrl' => $this->baseUrl,
            'timeoutMs' => $this->timeoutMs,
            'maxRetries' => $this->maxRetries,
            'simulate' => $this->simulate,
            'transport' => $this->transport->clientClass(),
        ];
    }

    /**
     * Serialising a client would put a live money-moving key into whatever sink the serialised blob
     * lands in - a session, a cache entry, a queue payload, a debug log. Refuse it loudly and name
     * the fix, rather than letting it fail obscurely inside the Closure or, worse, succeed.
     */
    public function __serialize(): array
    {
        throw new PaylodConfigError(
            'A Paylod client cannot be serialised: it holds a live API key, and a serialised copy '
            . 'would carry that key into a cache, a session or a queue payload. Construct the client '
            . 'where you need it (from the environment) instead of passing one around serialised.'
        );
    }

    /** The secrets this client holds, for scrubbing out of anything about to be thrown or logged. */
    private function redact(mixed $value): mixed
    {
        return Redact::apply($value, [($this->apiKey)(), ($this->webhookSecret)()]);
    }

    /** The same scrubbing, as a callable the validators can apply to an error body. */
    private function redactor(): \Closure
    {
        return fn (mixed $value): mixed => $this->redact($value);
    }

    /**
     * Validate wait options and return [timeoutMs, onPoll].
     *
     * Split out so `collectAndWait()` can run it BEFORE dispatching the charge: a bad wait option is
     * a programmer error, and a programmer error must never be reported after a customer's phone has
     * already rung.
     *
     * @param array<string,mixed> $options
     * @return array{0:int,1:?callable}
     */
    private static function parseWaitOptions(array $options): array
    {
        // Same rule as the client timeout: a non-positive wait budget would mean "wait forever".
        $timeoutMs = self::assertPositiveTimeoutMs(
            $options['timeoutMs'] ?? self::DEFAULT_WAIT_TIMEOUT_MS,
            'wait() timeoutMs'
        );
        $onPoll = $options['onPoll'] ?? null;
        if ($onPoll !== null && !is_callable($onPoll)) {
            throw new PaylodConfigError('wait() onPoll must be callable.');
        }

        return [$timeoutMs, $onPoll];
    }

    /**
     * THE DOUBLE-CHARGE GUARD, resolved before a single byte leaves the process.
     *
     * A generated key is NOT idempotency. It is a fresh value on every invocation, so it collapses
     * exactly nothing: a double-clicked Pay button, a refreshed tab, a redelivered queue job and a
     * process restart mid-request each mint a NEW key and each raise a SEPARATE charge. The old
     * behaviour - generate one and emit a once-per-process `trigger_error` - meant the protection
     * was off by default, and the warning was invisible in every production posture that matters
     * (`display_errors=0`, a log nobody reads, a custom error handler that swallows E_USER_WARNING,
     * or simply the second request in the worker's lifetime).
     *
     * So the key is REQUIRED. The only way to get a generated one is to say, in the call itself,
     * that you accept an unprotected charge - and it still warns, every single time, because there
     * is no posture in which this is a good idea outside a scratch script.
     *
     * @param array<string,mixed> $params
     */
    private static function resolveIdempotencyKey(array $params): string
    {
        if (isset($params['idempotencyKey'])) {
            // A caller-supplied key is the double-charge guard - reject a blank/whitespace/control-char
            // one loudly rather than silently drop protection.
            Validate::idempotencyKey($params['idempotencyKey']);

            return (string) $params['idempotencyKey'];
        }

        if (($params['unsafeGeneratedIdempotencyKey'] ?? false) !== true) {
            throw new PaylodInvalidRequestError(
                'collect() requires an idempotencyKey. Mint ONE KEY PER PAYMENT ATTEMPT - an id you '
                . 'create when the customer presses Pay and PERSIST on that attempt - and pass it '
                . 'here. Without it this charge has no double-charge protection at all: a '
                . 'double-clicked button, a refreshed tab, a redelivered job or a process restart '
                . 'will fire a SECOND STK prompt and can charge your customer twice. A key the SDK '
                . 'generates for you is not idempotency - it is different on every call, so it '
                . 'collapses nothing. If you genuinely want an unprotected charge (a scratch script, '
                . 'never production), pass "unsafeGeneratedIdempotencyKey" => true and accept that '
                . 'this call can double-charge. See https://paylod.dev/docs/sdk#idempotency'
            );
        }

        self::warnUnsafeGeneratedIdempotencyKey();

        return Uuid::v4();
    }

    private static function warnUnsafeGeneratedIdempotencyKey(): void
    {
        // Deliberately NOT once-per-process. The old once-per-process warning meant a worker that
        // handled a thousand charges warned about the first one only.
        trigger_error(
            '[paylod] collect() was called with unsafeGeneratedIdempotencyKey => true, so this '
            . 'charge is NOT protected against being sent twice. The key is freshly generated and '
            . 'therefore collapses nothing: a double-clicked Pay button, a refreshed tab, or a '
            . 'redelivered job will fire a SECOND STK prompt and can charge your customer twice. '
            . 'Pass ONE KEY PER PAYMENT ATTEMPT instead - an id you mint when the customer presses '
            . 'Pay, and persist on that attempt. See https://paylod.dev/docs/sdk#idempotency',
            E_USER_WARNING
        );
        self::$warnedMissingIdempotencyKey = true;
    }

    /**
     * A request timeout must be POSITIVE and bounded. cURL treats CURLOPT_TIMEOUT_MS of 0 as "no
     * timeout at all", so accepting 0 would turn a hung connection into a request that never returns
     * and a wait() that never settles - the opposite of what a caller passing timeoutMs wants.
     */
    private const MAX_TIMEOUT_MS = 600000;

    private static function assertPositiveTimeoutMs(mixed $value, string $label): int
    {
        if (is_bool($value) || (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value)))) {
            throw new PaylodConfigError("{$label} must be a positive number of milliseconds.");
        }
        $ms = (float) $value;
        if (!is_finite($ms) || $ms <= 0) {
            throw new PaylodConfigError(
                "{$label} must be greater than 0 (got " . var_export($value, true) . '). A zero or '
                . 'negative timeout disables the timeout entirely, so a hung request would never return.'
            );
        }
        // A FRACTIONAL timeout is the nastiest form of this bug: 0.5 is "greater than 0", passes
        // every bound below, and then (int) truncates it to 0 - which is precisely the value that
        // DISABLES cURL's timeout. A caller asking for half a millisecond would get an indefinite
        // hang. Whole milliseconds only, and they must be exactly representable as an int.
        if (fmod($ms, 1.0) !== 0.0) {
            throw new PaylodConfigError(
                "{$label} must be a WHOLE number of milliseconds (got " . var_export($value, true)
                . '). A fractional value truncates towards zero, and a truncated 0 disables the '
                . 'timeout entirely - the opposite of what you asked for.'
            );
        }
        if ($ms < 1 || $ms > self::MAX_TIMEOUT_MS) {
            throw new PaylodConfigError(
                "{$label} must be between 1 and " . self::MAX_TIMEOUT_MS . ' ms (got '
                . var_export($value, true) . ').'
            );
        }

        return (int) $ms;
    }

    /**
     * Retries are BOUNDED. The backoff is exponential (250ms * 2^n), so an unbounded maxRetries does
     * not just retry a lot - it sleeps for geometrically growing stretches: attempt 20 alone would
     * wait over a day. A request budget is a config value, not a licence to hang a worker forever.
     */
    private const MAX_RETRIES_LIMIT = 10;

    private static function assertMaxRetries(mixed $value): int
    {
        if (is_bool($value) || (!is_int($value) && !(is_float($value) && is_finite($value) && fmod($value, 1.0) === 0.0))) {
            throw new PaylodConfigError('maxRetries must be a whole number between 0 and ' . self::MAX_RETRIES_LIMIT . '.');
        }
        $n = (int) $value;
        if ($n < 0 || $n > self::MAX_RETRIES_LIMIT) {
            throw new PaylodConfigError(
                "maxRetries must be between 0 and " . self::MAX_RETRIES_LIMIT . " (got {$n}). The "
                . 'backoff between attempts doubles each time, so an unbounded retry count means '
                . 'unbounded sleeping, not merely more attempts.'
            );
        }

        return $n;
    }

    /**
     * Parse the Retry-After response header into milliseconds.
     *
     * Three things were wrong with the previous version, all of them reachable from a response an
     * upstream proxy can shape:
     *
     *  - The header was looked up by the EXACT key `retry-after`. Header names are case-insensitive
     *    (RFC 9110), so a transport that preserved `Retry-After` made the SDK silently ignore a
     *    server's explicit back-off instruction and hammer it on the fixed schedule instead.
     *  - `ctype_digit` accepts arbitrarily many digits, and `(int) $value * 1000` on a 30-digit
     *    number overflows to a float - which then raised a TypeError on the `int` return, turning a
     *    retryable 429 into a crash mid-payment.
     *  - `strtotime()` is permissive: "now", "+1 day", "tomorrow" and other non-HTTP-date forms all
     *    parsed, so a hostile or merely broken value could steer the client's sleep.
     *
     * Only the two forms the RFC actually defines are accepted: delta-seconds, and an IMF-fixdate.
     * The result is saturating and always clamped to [0, MAX_SLEEP_MS].
     *
     * @param array<string,string> $headers
     */
    private static function parseRetryAfterMs(array $headers): ?int
    {
        $value = null;
        foreach ($headers as $name => $candidate) {
            if (is_string($name) && strcasecmp($name, 'retry-after') === 0) {
                $value = $candidate;
                break;
            }
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // delta-seconds. Bounded to 9 digits BEFORE any arithmetic, so nothing can overflow; a
        // larger value is not rejected outright (the server did mean "wait a long time") but is
        // saturated to the ceiling, which the caller clamps further.
        if (ctype_digit($value)) {
            if (strlen($value) > 9) {
                return self::MAX_SLEEP_MS;
            }

            return (int) min((int) $value * 1000, self::MAX_SLEEP_MS);
        }

        // IMF-fixdate, strictly: "Wed, 21 Oct 2015 07:28:00 GMT". Parsed with an exact format and a
        // round-trip check, so no other date dialect gets in.
        $parsed = \DateTimeImmutable::createFromFormat(
            'D, d M Y H:i:s T',
            $value,
            new \DateTimeZone('UTC'),
        );
        if ($parsed === false || $parsed->format('D, d M Y H:i:s T') !== $value) {
            return null;
        }

        // Saturating: the subtraction is done in float so a year-3000 date cannot overflow, and the
        // result is clamped into range before it becomes an int.
        $deltaMs = ((float) $parsed->getTimestamp() - (float) time()) * 1000.0;
        if (!is_finite($deltaMs) || $deltaMs <= 0) {
            return 0;
        }

        return (int) min($deltaMs, (float) self::MAX_SLEEP_MS);
    }

    /**
     * A MONOTONIC millisecond clock, used for every deadline and every remaining-time computation.
     *
     * `microtime()` reads the WALL clock, and the wall clock moves. An NTP step, a daylight-saving
     * transition, a leap-second smear or an administrator running `date -s` mid-payment all shift
     * it - and a wait() deadline computed from it shifts with it. Backwards, a wait(30s) can hang
     * for as long as the clock was set back. Forwards, it expires INSTANTLY: the SDK throws
     * PaylodTimeoutError on a payment whose STK prompt is live on the customer's handset, and a
     * caller that treats a timeout as "start again" charges twice.
     *
     * `hrtime(true)` counts nanoseconds from an arbitrary but MONOTONIC origin. It cannot be
     * stepped, and it is unaffected by the system time entirely. The absolute value is meaningless
     * - which is fine, because every use here is a difference.
     */
    private static function nowMs(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }

    /**
     * A cause safe to attach to a thrown error.
     *
     * A wrapper whose message is carefully redacted, carrying the ORIGINAL throwable as `previous`,
     * is not redacted at all. `getPrevious()->getMessage()` still holds the raw text, and PHP's
     * default `__toString()` on the wrapper WALKS the previous chain and prints it - so the very
     * echoed bearer token the wrapper scrubbed reappears in the log line the framework writes, in
     * the exception page, and in any error tracker that serialises the chain. The original's stack
     * trace is worse: with `zend.exception_ignore_args=0` (the development default) it records call
     * arguments, and the frames below us are the ones that were handed the credential.
     *
     * So the original is DROPPED and a sanitized surrogate takes its place: the original's class
     * name and its redacted message, and nothing else. The diagnostic value - what kind of thing
     * went wrong, and where - survives; the secret does not travel with it.
     */
    private static function sanitizedCause(\Throwable $e, \Closure $redact): \Throwable
    {
        return new PaylodConnectionError(
            'sanitized cause: ' . $e::class . ': ' . (string) $redact($e->getMessage())
            . ' (the original throwable is deliberately NOT chained - its message, its trace and '
            . 'its recorded call arguments can carry the API key)'
        );
    }

    /** Remaining time to the deadline in ms, or null when there is no deadline. */
    private static function remaining(?int $deadlineMs): ?int
    {
        return $deadlineMs === null ? null : $deadlineMs - self::nowMs();
    }

    private static function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /**
     * The hard ceiling on any single sleep, deadline or no deadline.
     *
     * Without it, the only thing bounding a backoff was the operation deadline - and `collect()` has
     * none. An exponential ramp with no ceiling meant a plain retry could park a worker for hours on
     * one call. One minute is already far longer than any sane back-off for a payment.
     */
    private const MAX_SLEEP_MS = 60000;

    /**
     * A sleep clamped to the operation deadline AND to an unconditional ceiling, so a backoff can
     * never push past wait()'s cap - nor, when there is no cap, run away on its own.
     */
    private static function boundedSleepMs(int $ms, ?int $deadlineMs): void
    {
        self::sleepMs(self::cappedSleepMs($ms, $deadlineMs));
    }

    /** The clamp itself, separated from the sleeping so it can be asserted without waiting a minute. */
    private static function cappedSleepMs(int $ms, ?int $deadlineMs): int
    {
        $capped = min($ms, self::MAX_SLEEP_MS);
        $remaining = self::remaining($deadlineMs);
        if ($remaining !== null) {
            $capped = min($capped, max(0, $remaining));
        }

        return max(0, $capped);
    }

    /** +/-20% jitter so a fleet of servers doesn't poll in lockstep. */
    private static function jitter(int $ms): int
    {
        return (int) round($ms * (0.8 + (mt_rand() / mt_getrandmax()) * 0.4));
    }

    private static function pollDelay(int $attempt): int
    {
        $idx = min($attempt, count(self::POLL_SCHEDULE_MS) - 1);

        return self::jitter(self::POLL_SCHEDULE_MS[$idx]);
    }
}
