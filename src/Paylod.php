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
use Paylod\Support\Uuid;

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
     * @param array<string,mixed> $options Escape hatches: baseUrl, webhookSecret, timeoutMs,
     *   maxRetries, simulate, transport.
     */
    public function __construct(string|array|null $apiKey = null, array $options = [])
    {
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
        $this->timeoutMs = (int) ($options['timeoutMs'] ?? self::DEFAULT_TIMEOUT_MS);
        $this->maxRetries = (int) ($options['maxRetries'] ?? self::DEFAULT_MAX_RETRIES);
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
                if ($remaining <= 0) {
                    break; // out of time - surface the last error / a timeout below
                }
                $perRequestTimeout = min($perRequestTimeout, $remaining);
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

            $apiError = new PaylodApiError($message, $status, $parsed, $idempotencyKey);

            // 429 / transient 5xx are retried. A 409 is retried ONLY when it is explicitly "same key
            // still in progress" - every other 409 (body conflict, indeterminate) is a real answer.
            $transient = $status === 429 || ($status >= 500 && !isset(self::NON_TRANSIENT_5XX[$status]));
            $inProgress = $status === 409 && preg_match(self::IN_PROGRESS_409_RE, $message) === 1;
            if ((!$transient && !$inProgress) || $attempt === $this->maxRetries) {
                throw $apiError;
            }

            $lastError = $apiError;
            // Honour Retry-After (delta-seconds OR HTTP-date), clamped to 10s and the operation deadline.
            $retryAfterMs = self::parseRetryAfterMs($res['headers']['retry-after'] ?? null);
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
            self::assertValidIdempotencyKey($params['idempotencyKey']);
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

            // A 2xx with no payment id is INDETERMINATE: the charge may have moved. Fail with the key
            // attached rather than hand back an empty id a caller would treat as a new payment.
            $ack = $this->request('POST', '/collect', $body, $idempotencyKey, null, function (array $parsed, int $status) use ($idempotencyKey): void {
                $id = $parsed['paymentId'] ?? null;
                if (!is_string($id) || trim($id) === '') {
                    throw new PaylodApiError(
                        'paylod returned a 2xx response with no paymentId - the charge state is '
                        . 'INDETERMINATE. Read the payment with this idempotencyKey before starting any '
                        . 'new attempt; do NOT mint a fresh key (that risks a second charge).',
                        $status,
                        $parsed,
                        $idempotencyKey,
                        true,
                    );
                }
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
            // A 2xx status body with no id is malformed - surface it rather than return an empty Payment.
            $id = $parsed['id'] ?? null;
            if (!is_string($id) || trim($id) === '') {
                throw new PaylodApiError(
                    'paylod returned a 2xx status body with no payment id (malformed response).',
                    $status,
                    $parsed,
                );
            }
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
        $timeoutMs = (int) ($options['timeoutMs'] ?? self::DEFAULT_WAIT_TIMEOUT_MS);
        $onPoll = $options['onPoll'] ?? null;
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
        $ack = $this->collect($params);

        return $this->wait($ack['paymentId'], $options);
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
        ?string $secret = null,
        int $toleranceSec = Webhook::DEFAULT_TOLERANCE_SEC,
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
        ?string $secret = null,
        int $toleranceSec = Webhook::DEFAULT_TOLERANCE_SEC,
    ): array {
        return Webhook::verify($rawBody, $signatureHeader, $secret ?? $this->webhookSecret ?? '', $toleranceSec);
    }

    // -- Internals ------------------------------------------------------------

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
     * Enforce a secure origin for baseUrl. HTTPS is required so the API key is never sent in the
     * clear and a hostile redirect target can't be substituted. Loopback HTTP is permitted ONLY
     * behind an explicit test-only opt-in, and NEVER with a live (mp_live_) key.
     */
    private static function assertSecureBaseUrl(string $baseUrl, string $apiKey, bool $allowInsecure): void
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new PaylodConfigError("baseUrl is not a valid URL: \"{$baseUrl}\".");
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme === 'https') {
            return;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        $isLive = str_starts_with($apiKey, 'mp_live_');

        if ($scheme === 'http' && $isLoopback && $allowInsecure && !$isLive) {
            return;
        }

        throw new PaylodConfigError(
            "baseUrl must use https:// (got \"{$baseUrl}\"). Plaintext HTTP would transmit your API key "
            . 'in the clear and opens you to SSRF / redirection. Loopback HTTP (localhost, 127.0.0.1) is '
            . "allowed ONLY with ['allowInsecureBaseUrl' => true] and NEVER with an mp_live_ key."
        );
    }

    /**
     * Reject an idempotency key that would silently drop double-charge protection: blank/whitespace
     * keys, keys carrying control characters (which also cannot go in an HTTP header), and absurdly
     * long values. A caller-supplied key is the ONE thing standing between a double-click and a
     * double-charge, so a bad one must fail loudly rather than be quietly accepted.
     */
    private static function assertValidIdempotencyKey(mixed $key): void
    {
        if (!is_string($key) || trim($key) === '') {
            throw new PaylodInvalidRequestError(
                'idempotencyKey must be a non-empty, non-whitespace string - a blank key silently drops '
                . 'double-charge protection.'
            );
        }
        // Control chars (C0 range + DEL): invalid in HTTP header values and a sign of a bad key.
        if (preg_match('/[\x00-\x1f\x7f]/', $key) === 1) {
            throw new PaylodInvalidRequestError(
                'idempotencyKey must not contain control characters (tabs, newlines, NULs, etc.).'
            );
        }
        if (strlen($key) > 255) {
            throw new PaylodInvalidRequestError('idempotencyKey must be 255 characters or fewer.');
        }
    }

    /**
     * Parse a Retry-After header value into milliseconds. Accepts BOTH forms the RFC allows: a
     * delta-seconds integer ("5") and an HTTP-date ("Wed, 21 Oct 2015 07:28:00 GMT"). Returns null
     * when absent/unparseable, and never a negative delay.
     */
    private static function parseRetryAfterMs(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value * 1000;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        $deltaMs = ($ts - time()) * 1000;

        return $deltaMs > 0 ? $deltaMs : 0;
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

    /** A sleep clamped to the operation deadline, so a backoff can never push past wait()'s cap. */
    private static function boundedSleepMs(int $ms, ?int $deadlineMs): void
    {
        $capped = $ms;
        $remaining = self::remaining($deadlineMs);
        if ($remaining !== null) {
            $capped = min($capped, max(0, $remaining));
        }
        if ($capped > 0) {
            self::sleepMs($capped);
        }
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
