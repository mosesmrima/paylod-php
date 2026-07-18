<?php

declare(strict_types=1);

namespace Paylod;

use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodConfigError;
use Paylod\Exceptions\PaylodConnectionError;
use Paylod\Exceptions\PaylodInvalidRequestError;
use Paylod\Exceptions\PaylodTimeoutError;
use Paylod\Http\CurlTransport;
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

    private string $apiKey;
    private string $baseUrl;
    private ?string $webhookSecret;
    private int $timeoutMs;
    private int $maxRetries;
    private bool $simulate;
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
     *   maxRetries, simulate, transport. Also sensitive: it carries webhookSecret (and apiKey, in
     *   the object form).
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
        $this->apiKey = trim($key);

        // Baked in. PAYLOD_BASE_URL / baseUrl remain as escape hatches for self-hosting and tests.
        $base = $options['baseUrl'] ?? (getenv('PAYLOD_BASE_URL') ?: null) ?? self::DEFAULT_BASE_URL;
        $this->baseUrl = rtrim((string) $base, '/');

        // Reject a plaintext / non-canonical origin BEFORE any key can leave the process. Loopback
        // HTTP is allowed only behind an explicit test-only flag, and never with a live key.
        self::assertSecureBaseUrl($this->baseUrl, $this->apiKey, ($options['allowInsecureBaseUrl'] ?? false) === true);

        $this->webhookSecret = $options['webhookSecret'] ?? (getenv('PAYLOD_WEBHOOK_SECRET') ?: null);
        // A zero/negative timeout would DISABLE cURL's timeout - reject it rather than ship a client
        // that can hang forever.
        $this->timeoutMs = self::assertPositiveTimeoutMs($options['timeoutMs'] ?? self::DEFAULT_TIMEOUT_MS, 'timeoutMs');
        $this->maxRetries = self::assertMaxRetries($options['maxRetries'] ?? self::DEFAULT_MAX_RETRIES);
        $this->transport = $options['transport'] ?? new CurlTransport();

        // Simulator mode is a TEST posture, so it is fenced off from production at CONSTRUCTION time.
        $this->simulate = ($options['simulate'] ?? false) === true;
        if ($this->simulate) {
            Simulator::assertSandboxKey($this->apiKey, 'new Paylod(..., ["simulate" => true])');
        }

        $this->simulator = new Simulator(
            $this->apiKey,
            fn (array $opts): array => $this->request(
                (string) $opts['method'],
                (string) $opts['path'],
                $opts['body'] ?? null,
                $opts['idempotencyKey'] ?? null,
            ),
        );
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
        $url = $this->baseUrl . $path;
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

            $headers = [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ];
            $payload = null;
            if ($body !== null) {
                $headers['Content-Type'] = 'application/json';
                $payload = json_encode($body, JSON_THROW_ON_ERROR);
            }
            // Sent on every mutating call - this is what makes a retry safe.
            if ($idempotencyKey !== null) {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }

            try {
                $res = $this->transport->send($method, $url, $headers, $payload, $perRequestTimeout);
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

        throw $lastError ?? new PaylodConnectionError("Request to {$url} failed");
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
     * Pass `idempotencyKey`, and mint ONE KEY PER PAYMENT ATTEMPT - one press of Pay, not an order
     * and never a product. Duplicates of that attempt collapse into one payment and one prompt.
     *
     * @param array{amount:int|float,phone:string,accountReference?:string,description?:string,metadata?:array<string,mixed>,idempotencyKey?:string} $params
     * @return array{paymentId:string,status:string,checkoutRequestId:string,idempotencyKey:string}
     */
    public function collect(array $params): array
    {
        $body = $this->buildCollectBody($params);
        if (!isset($params['idempotencyKey'])) {
            self::warnMissingIdempotencyKey();
        } else {
            // A caller-supplied key is the double-charge guard - reject a blank/whitespace/control-char
            // one loudly rather than silently drop protection. A generated key is always well-formed.
            Validate::idempotencyKey($params['idempotencyKey']);
        }
        $idempotencyKey = $params['idempotencyKey'] ?? Uuid::v4();

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
            }
            throw $e;
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

        $p = $this->request('GET', '/status/' . rawurlencode($paymentId), null, null, $deadlineMs, function (array $parsed, int $status): void {
            // The FULL payment schema, including the rule that a `success` must carry evidence (a
            // receipt or result code 0) - the status string on its own is a claim, not a proof, and
            // shipping goods against an evidence-free claim loses real money.
            Validate::paymentBody($parsed, $status, $this->redactor());
        });

        return [
            'id' => (string) ($p['id'] ?? $paymentId),
            'status' => (string) ($p['status'] ?? 'pending'),
            'mpesaReceipt' => $p['mpesaReceipt'] ?? null,
            'resultCode' => $p['resultCode'] ?? null,
            'resultDesc' => $p['resultDesc'] ?? null,
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
                $e->attachIdempotencyKey($ack['idempotencyKey']);
                $e->attachPaymentId($ack['paymentId']);
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
                $e,
            );
            $wrapped->attachPaymentId($ack['paymentId']);

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
        return Webhook::isValid($rawBody, $signatureHeader, $secret ?? $this->webhookSecret ?? '', $toleranceSec);
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
        return Webhook::verify($rawBody, $signatureHeader, $secret ?? $this->webhookSecret ?? '', $toleranceSec);
    }

    // -- Internals ------------------------------------------------------------

    /**
     * What print_r()/var_dump()/var_export-style debugging actually shows for this object.
     *
     * PHP dumps PRIVATE properties too, so without this a single `print_r($paylod)` in a debug
     * branch - or a framework's exception page, or a queue worker logging its job payload - prints
     * the live API key and the webhook secret verbatim. Only masked prefixes are exposed here:
     * enough to tell "wrong environment" at a glance, never enough to use.
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => Redact::mask($this->apiKey),
            'webhookSecret' => Redact::mask($this->webhookSecret),
            'baseUrl' => $this->baseUrl,
            'timeoutMs' => $this->timeoutMs,
            'maxRetries' => $this->maxRetries,
            'simulate' => $this->simulate,
            'transport' => get_class($this->transport),
        ];
    }

    /** The secrets this client holds, for scrubbing out of anything about to be thrown or logged. */
    private function redact(mixed $value): mixed
    {
        return Redact::apply($value, [$this->apiKey, $this->webhookSecret]);
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

    private static function warnMissingIdempotencyKey(): void
    {
        if (self::$warnedMissingIdempotencyKey) {
            return;
        }
        self::$warnedMissingIdempotencyKey = true;
        trigger_error(
            '[paylod] collect() was called without an idempotencyKey, so this charge is not '
            . 'protected against being sent twice. A double-clicked Pay button, a refreshed tab, or '
            . 'a redelivered job will fire a SECOND STK prompt and can charge your customer twice. '
            . 'Pass ONE KEY PER PAYMENT ATTEMPT - an id you mint when the customer presses Pay, and '
            . 'persist on that attempt. See https://paylod.dev/docs/sdk#idempotency',
            E_USER_WARNING
        );
    }

    /**
     * The ONLY origins a live bearer key may be sent to. HTTPS alone is not enough: any
     * attacker-controlled https:// host would happily accept the Authorization header and replay it.
     *
     * @var list<string>
     */
    private const ALLOWED_HOSTS = ['paylod.dev', 'api.paylod.dev'];

    /** Hosts that may be used with the explicit, test-only insecure opt-in. */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /**
     * Enforce a secure, ALLOWLISTED origin for baseUrl before the API key can leave the process.
     *
     * HTTPS is necessary but nowhere near sufficient - a bearer key posted to https://evil.example is
     * just as stolen as one posted over plaintext. So the host itself must be the canonical paylod
     * production origin. We additionally reject anything that smuggles a different effective target
     * past a naive eyeball check: userinfo (https://paylod.dev@evil.example), a missing host, a
     * non-default port, a query string or fragment, and private / loopback / link-local IPs.
     *
     * Loopback is permitted ONLY behind the explicit test-only opt-in, and NEVER with a live
     * (mp_live_) key.
     */
    private static function assertSecureBaseUrl(string $baseUrl, string $apiKey, bool $allowInsecure): void
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme']) || !isset($parts['host']) || $parts['host'] === '') {
            throw new PaylodConfigError("baseUrl is not a valid absolute URL: \"{$baseUrl}\".");
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = $parts['port'] ?? null;
        $isLive = str_starts_with($apiKey, 'mp_live_');

        // Credentials in the URL are never legitimate here, and `https://paylod.dev@evil.example`
        // reads as the real origin while resolving to the attacker's.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new PaylodConfigError(
                "baseUrl must not contain credentials (got \"{$baseUrl}\"). A userinfo section makes the "
                . 'URL read like the paylod origin while pointing somewhere else entirely.'
            );
        }
        // The base URL is a prefix we concatenate paths onto - a query or fragment would be silently
        // relocated into the middle of the request line.
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new PaylodConfigError(
                "baseUrl must not contain a query string or fragment (got \"{$baseUrl}\")."
            );
        }

        $isLoopbackHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || in_array($host, self::LOOPBACK_HOSTS, true);

        // The sanctioned test escape hatch: an explicit opt-in, a loopback host, and never a live key.
        if ($isLoopbackHost) {
            if ($allowInsecure && !$isLive && ($scheme === 'http' || $scheme === 'https')) {
                return;
            }
            throw new PaylodConfigError(
                "baseUrl points at loopback (\"{$baseUrl}\"). That is allowed ONLY with "
                . "['allowInsecureBaseUrl' => true] and NEVER with an mp_live_ key."
            );
        }

        if ($scheme !== 'https') {
            throw new PaylodConfigError(
                "baseUrl must use https:// (got \"{$baseUrl}\"). Plaintext HTTP would transmit your API "
                . 'key in the clear and opens you to SSRF / redirection.'
            );
        }

        // A bare IP literal is never the paylod origin, and private / link-local ranges are the classic
        // SSRF pivot (169.254.169.254 and friends).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new PaylodConfigError(
                "baseUrl must name the paylod host, not a raw IP address (got \"{$baseUrl}\")."
            );
        }

        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new PaylodConfigError(
                "baseUrl origin \"{$host}\" is not a paylod origin. Your API key can move money, so it is "
                . 'only ever sent to ' . implode(' or ', self::ALLOWED_HOSTS) . '. Remove the custom '
                . 'baseUrl / PAYLOD_BASE_URL override.'
            );
        }

        if ($port !== null && (int) $port !== 443) {
            throw new PaylodConfigError(
                "baseUrl must use the default https port (got port {$port} in \"{$baseUrl}\")."
            );
        }
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

    private static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
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
