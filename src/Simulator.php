<?php

declare(strict_types=1);

namespace Paylod;

use Closure;
use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodInvalidRequestError;
use Paylod\Exceptions\PaylodSandboxOnlyError;
use Paylod\Support\Redact;
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

        // THE PRODUCTION AMOUNT RULE, not a local approximation of it. The simulator required only
        // "a positive int", so 10,000,000 KES dispatched here and was refused by the client - the
        // exact inversion of what a simulator is for. See Validate::collectAmount().
        $amount = Validate::collectAmount($params['amount'] ?? 1, 'simulate.collect');

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

        // THE SAME KEY RESOLVER PRODUCTION USES - required, or an explicit warned opt-in.
        //
        // A missing key used to be silently GENERATED here. That made this surface the one place a
        // developer could dispatch an unprotected charge without being told, on the very surface
        // they use to convince themselves that a double-click cannot charge twice. Resolved BEFORE
        // anything is dispatched, like every other rule in this method.
        $idempotencyKey = Validate::collectIdempotencyKey($params, 'simulate.collect', static function (): void {
            trigger_error(
                '[paylod] simulate.collect() was called with unsafeGeneratedIdempotencyKey => true, '
                . 'so this simulated charge is NOT protected against being sent twice - exactly as '
                . 'it would not be in production. Pass ONE KEY PER PAYMENT ATTEMPT instead.',
                E_USER_WARNING
            );
        });
        $redact = $this->redactor();

        try {
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
            'validate' => static function (#[\SensitiveParameter] array $parsed, int $status) use ($idempotencyKey, $redact): void {
                Validate::collectAck($parsed, $status, $idempotencyKey, $redact);
            },
            ]);
        } catch (\Throwable $e) {
            // THE EFFECTIVE KEY SURVIVES THE FAILURE. Whatever went wrong - network, timeout, 5xx,
            // malformed 2xx - the caller must be able to retry with the SAME key; a fresh one is a
            // second charge. The client's collect() has carried this context since round 4; this
            // dispatch surface dropped it on the floor, so a simulator failure taught the caller the
            // opposite reflex to the one production requires.
            if ($e instanceof \Paylod\Exceptions\PaylodException) {
                $e->attachIdempotencyKey($idempotencyKey);

                throw $e;
            }

            throw new PaylodApiError(
                'simulate.collect() failed with an unexpected error: '
                . (string) $redact($e->getMessage()) . ' - the simulated charge state is '
                . 'INDETERMINATE. Read the payment with this idempotencyKey before starting any new '
                . 'attempt; do NOT mint a fresh key.',
                0,
                null,
                $idempotencyKey,
                true,
            );
        }

        return [
            'paymentId' => (string) $ack['paymentId'],
            'status' => 'pending',
            'checkoutRequestId' => (string) $ack['checkoutRequestId'],
            // REBUILT FROM THE CLOSED SET, not forwarded. `outcomes` was returned exactly as the
            // server sent it - unvalidated and unredacted - into a value callers print and log, so
            // an echoed API key rode out through ordinary (non-error) output. There are exactly five
            // legal outcomes and they are compiled into this class, so anything else is noise at
            // best and a credential at worst.
            'outcomes' => self::allowlistedOutcomes($ack['outcomes'] ?? []),
        ];
    }

    /**
     * The acknowledged `outcomes` list, reduced to the closed set this SDK defines.
     *
     * @return list<string>
     */
    private static function allowlistedOutcomes(mixed $outcomes): array
    {
        if (!is_array($outcomes)) {
            return [];
        }

        $out = [];
        foreach ($outcomes as $outcome) {
            if (is_string($outcome) && in_array($outcome, self::OUTCOMES, true)) {
                $out[] = $outcome;
            }
        }

        return array_values(array_unique($out));
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

        // THE DERIVED KEY IS BUILT FROM CALLER INPUT, so its inputs are checked before it is built.
        //
        // `sim-outcome-{$paymentId}-{$outcome}` interpolates two unvalidated strings into a value
        // that goes out as an `Idempotency-Key` HTTP HEADER. Neither was checked: `$outcome` was
        // forwarded to the API to be rejected there, and `$paymentId` only had to be non-empty. So a
        // newline, a NUL, a non-ASCII character or a megabyte of text landed in a header value -
        // the exact class of input {@see Validate::idempotencyKey()} exists to refuse on the
        // production path, bypassed on the simulator path because the key was DERIVED rather than
        // supplied. "The SDK generated it" is not a reason to trust a string the caller wrote most
        // of, and a simulator that accepts a request production would reject teaches the wrong
        // lesson about the path it stands in for.
        self::assertKnownOutcome($outcome);

        $idempotencyKey = "sim-outcome-{$paymentId}-{$outcome}";
        // Run the PRODUCTION key rules over the derived value. With `$outcome` now closed, this is
        // effectively a check on `$paymentId` - which is exactly the point: the id is caller input.
        Validate::idempotencyKey($idempotencyKey);

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
            'idempotencyKey' => $idempotencyKey,
            // The settle response describes a PAYMENT, so it runs the payment validator - the same
            // one status() runs, ID BINDING (law L1) INCLUDED. This surface previously validated
            // against a hardcoded 200 and did not bind at all, so a body describing a DIFFERENT
            // payment was classified on its merits and returned as this payment's outcome.
            'validate' => static function (#[\SensitiveParameter] array $parsed, int $status) use ($paymentId, $redact): void {
                Validate::paymentBody(self::normalizeSettleAck($parsed), $status, $redact, $paymentId);
            },
        ]);

        $payment = self::normalizeSettleAck($ack);

        return [
            'outcome' => PaymentOutcome::fromPayment($payment),
            'webhookQueued' => ($ack['webhookQueued'] ?? true) !== false,
        ];
    }

    /** The closed outcome set, checked in one place so every surface refuses the same values. */
    private static function assertKnownOutcome(mixed $outcome): void
    {
        if (!is_string($outcome) || !in_array($outcome, self::OUTCOMES, true)) {
            throw new PaylodInvalidRequestError(
                'simulate.outcome(): outcome must be one of ' . implode(', ', self::OUTCOMES)
                . ' (got ' . json_encode($outcome) . ').'
            );
        }
    }

    /**
     * collect() + outcome() in one call - the whole point of the simulator, in one line.
     *
     * @param array{outcome:string,phone?:string,amount?:int,accountReference?:string,description?:string,metadata?:array<string,mixed>,idempotencyKey?:string} $params
     * @return array{outcome:PaymentOutcome,webhookQueued:bool}
     */
    public function pay(array $params): array
    {
        // EVERY INPUT IS VALIDATED BEFORE ANYTHING IS CREATED. `$outcome` used to be checked inside
        // outcome(), i.e. AFTER collect() had already created a payment - so a typo'd outcome left a
        // stranded pending payment behind and reported a validation error, which is a mutation
        // performed on the way to rejecting the request that caused it.
        $outcome = $params['outcome'] ?? null;
        self::assertKnownOutcome($outcome);
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
