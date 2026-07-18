<?php

declare(strict_types=1);

namespace Paylod;

use Closure;
use Paylod\Exceptions\PaylodInvalidRequestError;
use Paylod\Exceptions\PaylodSandboxOnlyError;
use Paylod\Support\Redact;
use Paylod\Support\Uuid;
use Paylod\Support\Validate;

/**
 * The sandbox simulator - a phone that isn't there.
 *
 * It removes the handset and NOTHING else: it creates a REAL sandbox payment row, settles through
 * the SAME code path a real Daraja callback takes, carries the REAL Daraja result codes, and fires
 * a REAL signed webhook. status() / check() / wait() read it like any other payment.
 *
 * Sandbox only, structurally: every method refuses a mp_live_ key LOCALLY, before a byte leaves
 * the process.
 */
final class Simulator
{
    /** The five things that can happen to an STK prompt. */
    public const OUTCOMES = ['approve', 'wrong_pin', 'insufficient_funds', 'user_cancelled', 'timeout'];

    /** Safaricom's own sandbox test MSISDN. Nothing is ever sent to it. */
    private const DEFAULT_SIM_PHONE = '254708374149';

    private const SANDBOX_PREFIX = 'mp_test_';
    private const LIVE_PREFIX = 'mp_live_';

    /**
     * The credential, behind a closure - see {@see \Paylod\Paylod::$apiKey} for why a string
     * property is not good enough (var_export() ignores __debugInfo() and dumps real properties).
     *
     * @var Closure():string
     */
    private Closure $apiKey;

    /** The masked prefix, safe to print. */
    private ?string $apiKeyMasked;

    /**
     * @var Closure(array{method:string,path:string,body?:mixed,idempotencyKey?:string,validate?:?\Closure}):array<string,mixed>
     */
    private Closure $request;

    /**
     * @param Closure():string $apiKey
     * @param Closure(array{method:string,path:string,body?:mixed,idempotencyKey?:string,validate?:?\Closure}):array<string,mixed> $request
     */
    public function __construct(Closure $apiKey, Closure $request)
    {
        $this->apiKey = $apiKey;
        $this->apiKeyMasked = Redact::mask(($apiKey)());
        $this->request = $request;
    }

    /**
     * Refuse a production key locally. Called by every simulator method, before any request.
     */
    public static function assertSandboxKey(#[\SensitiveParameter] string $apiKey, string $what): void
    {
        if (str_starts_with($apiKey, self::SANDBOX_PREFIX)) {
            return;
        }
        $kind = str_starts_with($apiKey, self::LIVE_PREFIX)
            ? 'a production (mp_live_) key'
            : 'a key that is not a sandbox (mp_test_) key';

        throw new PaylodSandboxOnlyError(
            "{$what} refused: you gave it {$kind}. "
            . 'The simulator only ever creates SANDBOX payments, so a production key is categorically '
            . 'the wrong credential - no amount of retrying or key-rotating will make this work. '
            . 'Use your mp_test_ key here. Nothing is ever sent to a real phone.'
        );
    }

    /**
     * Masked rendering for print_r()/var_dump() - the simulator holds the API key too, and a dumped
     * client object reaches it through the public `simulator` property.
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return ['apiKey' => $this->apiKeyMasked];
    }

    /** Scrub the key out of anything attached to an error raised from here. */
    private function redactor(): Closure
    {
        return fn (mixed $value): mixed => Redact::apply($value, [($this->apiKey)()]);
    }

    /** The five outcomes, as a list. */
    public function outcomes(): array
    {
        return self::OUTCOMES;
    }

    /**
     * A request field that must be a string, checked HERE rather than at JSON-encoding time or at
     * the API - so the error names the field the caller got wrong.
     */
    private static function requireString(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new PaylodInvalidRequestError(
                "simulate.collect(): {$field} must be a string (got " . get_debug_type($value) . ').'
            );
        }

        return $value;
    }

    /**
     * Create a real, pending, sandbox payment. No phone rings.
     *
     * @param array{phone?:string,amount?:int,accountReference?:string,description?:string,metadata?:array<string,mixed>,idempotencyKey?:string} $params
     * @return array{paymentId:string,status:string,checkoutRequestId:string,outcomes:array<int,mixed>}
     */
    public function collect(array $params = []): array
    {
        self::assertSandboxKey(($this->apiKey)(), 'simulate.collect()');

        $amount = $params['amount'] ?? 1;
        if (!is_int($amount) || $amount <= 0) {
            throw new PaylodInvalidRequestError(
                "simulate.collect(): amount must be a positive whole number of KES (got {$amount})."
            );
        }

        $body = [
            'phone' => array_key_exists('phone', $params)
                ? Phone::normalize(self::requireString($params['phone'], 'phone'))
                : self::DEFAULT_SIM_PHONE,
            'amount' => $amount,
        ];

        // ARRAY_KEY_EXISTS, NOT ISSET - and every value type-checked before it is forwarded.
        //
        // `isset()` is false for a key that is PRESENT with a null value, so `['description' =>
        // null]` was silently dropped from the body rather than forwarded or rejected. That matters
        // precisely because of the comment below: the idempotency layer FINGERPRINTS this body. A
        // field the SDK drops is a field the fingerprint does not see, so the simulator would let a
        // reused key with a changed (null-ed) description replay happily while production, which
        // sees the difference, answers 409. The simulator exists to prove things about the
        // production path; one that silently disagrees with it about what a request IS teaches the
        // wrong lesson, and the lesson in question is "this cannot charge twice".
        //
        // The values were also forwarded unvalidated - an array where a string belongs, an object,
        // a resource - and only failed later, at JSON encoding or at the API, by which time the
        // caller has no idea which field was wrong.
        if (array_key_exists('accountReference', $params)) {
            // The backend calls this field `accountRef`; the SDK calls it `accountReference`.
            $body['accountRef'] = self::requireString($params['accountReference'], 'accountReference');
        }
        if (array_key_exists('description', $params)) {
            $body['description'] = self::requireString($params['description'], 'description');
        }
        if (array_key_exists('metadata', $params)) {
            $metadata = $params['metadata'];
            if (!is_array($metadata)) {
                throw new PaylodInvalidRequestError(
                    'simulate.collect(): metadata must be an array (got ' . get_debug_type($metadata) . ').'
                );
            }
            $body['metadata'] = $metadata;
        }

        if (isset($params['idempotencyKey'])) {
            // The SAME key rules production enforces. The simulator exists so a test can prove "a
            // double-click cannot charge twice" against the real thing - a simulator that accepted a
            // key production would reject would make that test a lie.
            Validate::idempotencyKey($params['idempotencyKey']);
        }
        // A missing key is GENERATED rather than omitted. Settling is a mutating call, and a
        // simulator that dispatches an unkeyed charge cannot be used to prove anything about the
        // keyed production path it stands in for.
        $idempotencyKey = $params['idempotencyKey'] ?? Uuid::v4();
        $redact = $this->redactor();

        $ack = ($this->request)([
            'method' => 'POST',
            'path' => '/simulate/collect',
            'body' => $body,
            'idempotencyKey' => $idempotencyKey,
            // THE SAME validator production runs, INSIDE the request, with the REAL HTTP status -
            // including the 202 requirement. Validating after the fact meant the status was not
            // available, so the check was run against a hardcoded 200 and the ack could not be
            // rejected on it. A simulator that tolerates an acknowledgement production would reject
            // teaches the wrong thing about the shape of a real response.
            'validate' => static function (array $parsed, int $status) use ($idempotencyKey, $redact): void {
                Validate::collectAck($parsed, $status, $idempotencyKey, $redact);
            },
        ]);

        return [
            'paymentId' => (string) $ack['paymentId'],
            'status' => 'pending',
            'checkoutRequestId' => (string) $ack['checkoutRequestId'],
            'outcomes' => $ack['outcomes'] ?? [],
        ];
    }

    /**
     * Force how a simulated payment resolves, and get back the ordinary PaymentOutcome - decoded,
     * renderable, with retryable already correct. A real signed webhook fires as a side effect.
     *
     * @return array{outcome:PaymentOutcome,webhookQueued:bool}
     */
    public function outcome(string $paymentId, string $outcome): array
    {
        self::assertSandboxKey(($this->apiKey)(), 'simulate.outcome()');
        if ($paymentId === '') {
            throw new PaylodInvalidRequestError('simulate.outcome(): paymentId is required.');
        }

        $redact = $this->redactor();

        $ack = ($this->request)([
            'method' => 'POST',
            'path' => '/simulate/outcome',
            'body' => ['paymentId' => $paymentId, 'outcome' => $outcome],
            // Settling is a MUTATING call and it used to carry no idempotency key at all, so a
            // network retry could re-dispatch it. The key is derived deterministically from the
            // operation, which is exactly the right shape here: retrying "settle THIS payment as
            // THIS outcome" is the same operation and must replay, while settling it as a different
            // outcome is a different operation.
            'idempotencyKey' => "sim-outcome-{$paymentId}-{$outcome}",
            // The settle response describes a PAYMENT, so it runs the payment validator - the same
            // one status() runs, ID BINDING (law L1) INCLUDED. This surface previously validated
            // against a hardcoded 200 and did not bind at all, so a body describing a DIFFERENT
            // payment was classified on its merits and returned as this payment's outcome.
            'validate' => static function (array $parsed, int $status) use ($paymentId, $redact): void {
                Validate::paymentBody(self::normalizeSettleAck($parsed), $status, $redact, $paymentId);
            },
        ]);

        $payment = self::normalizeSettleAck($ack);

        return [
            'outcome' => PaymentOutcome::fromPayment($payment),
            'webhookQueued' => ($ack['webhookQueued'] ?? true) !== false,
        ];
    }

    /**
     * collect() + outcome() in one call - the whole point of the simulator, in one line.
     *
     * @param array{outcome:string,phone?:string,amount?:int,accountReference?:string,description?:string,metadata?:array<string,mixed>,idempotencyKey?:string} $params
     * @return array{outcome:PaymentOutcome,webhookQueued:bool}
     */
    public function pay(array $params): array
    {
        $outcome = $params['outcome'];
        unset($params['outcome']);
        $created = $this->collect($params);

        return $this->outcome($created['paymentId'], $outcome);
    }

    /**
     * The settle acknowledgement, reshaped into the payment record shape the validators and the
     * semantic model speak. Done in ONE place so the body that is validated is byte-for-byte the
     * body that is judged - normalising differently in the two would make the binding check guard a
     * record nobody then acts on.
     *
     * @param array<string,mixed> $ack
     * @return array<string,mixed>
     */
    private static function normalizeSettleAck(array $ack): array
    {
        return [
            'id' => $ack['paymentId'] ?? null,
            'status' => $ack['status'] ?? null,
            'mpesaReceipt' => $ack['mpesaReceipt'] ?? null,
            'resultCode' => $ack['resultCode'] ?? null,
            'resultDesc' => $ack['resultDesc'] ?? null,
        ];
    }
}
