<?php

declare(strict_types=1);

namespace Paylod;

use Closure;
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

    private string $apiKey;

    /** @var Closure(array{method:string,path:string,body?:mixed,idempotencyKey?:string}):array<string,mixed> */
    private Closure $request;

    /**
     * @param Closure(array{method:string,path:string,body?:mixed,idempotencyKey?:string}):array<string,mixed> $request
     */
    public function __construct(#[\SensitiveParameter] string $apiKey, Closure $request)
    {
        $this->apiKey = $apiKey;
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
        return ['apiKey' => Redact::mask($this->apiKey)];
    }

    /** Scrub the key out of anything attached to an error raised from here. */
    private function redactor(): Closure
    {
        return fn (mixed $value): mixed => Redact::apply($value, [$this->apiKey]);
    }

    /** The five outcomes, as a list. */
    public function outcomes(): array
    {
        return self::OUTCOMES;
    }

    /**
     * Create a real, pending, sandbox payment. No phone rings.
     *
     * @param array{phone?:string,amount?:int,accountReference?:string,description?:string,metadata?:array<string,mixed>,idempotencyKey?:string} $params
     * @return array{paymentId:string,status:string,checkoutRequestId:string,outcomes:array<int,mixed>}
     */
    public function collect(array $params = []): array
    {
        self::assertSandboxKey($this->apiKey, 'simulate.collect()');

        $amount = $params['amount'] ?? 1;
        if (!is_int($amount) || $amount <= 0) {
            throw new PaylodInvalidRequestError(
                "simulate.collect(): amount must be a positive whole number of KES (got {$amount})."
            );
        }

        $body = [
            'phone' => isset($params['phone']) ? Phone::normalize($params['phone']) : self::DEFAULT_SIM_PHONE,
            'amount' => $amount,
        ];
        // The backend calls this field `accountRef`; the SDK calls it `accountReference`.
        if (isset($params['accountReference'])) {
            $body['accountRef'] = $params['accountReference'];
        }
        // Send the FULL body - the idempotency layer fingerprints it, so a dropped field would let
        // a reused key with changed description/metadata replay here while it 409s in production.
        if (isset($params['description'])) {
            $body['description'] = $params['description'];
        }
        if (isset($params['metadata'])) {
            $body['metadata'] = $params['metadata'];
        }

        $call = ['method' => 'POST', 'path' => '/simulate/collect', 'body' => $body];
        if (isset($params['idempotencyKey'])) {
            // The SAME key rules production enforces. The simulator exists so a test can prove "a
            // double-click cannot charge twice" against the real thing - a simulator that accepted a
            // key production would reject would make that test a lie.
            Validate::idempotencyKey($params['idempotencyKey']);
            $call['idempotencyKey'] = $params['idempotencyKey'];
        }

        $ack = ($this->request)($call);

        // And the SAME acknowledgement schema. Reading paymentId straight out of the body used to
        // produce an empty id (or a TypeError) from a malformed 2xx instead of a keyed indeterminate
        // error, which is exactly the failure mode the production path was hardened against.
        Validate::collectAck($ack, 200, $params['idempotencyKey'] ?? null, $this->redactor());

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
        self::assertSandboxKey($this->apiKey, 'simulate.outcome()');
        if ($paymentId === '') {
            throw new PaylodInvalidRequestError('simulate.outcome(): paymentId is required.');
        }

        $ack = ($this->request)([
            'method' => 'POST',
            'path' => '/simulate/outcome',
            'body' => ['paymentId' => $paymentId, 'outcome' => $outcome],
        ]);

        $payment = [
            'id' => $ack['paymentId'] ?? null,
            'status' => $ack['status'] ?? null,
            'mpesaReceipt' => $ack['mpesaReceipt'] ?? null,
            'resultCode' => $ack['resultCode'] ?? null,
            'resultDesc' => $ack['resultDesc'] ?? null,
        ];
        // Same payment-schema rules as status(), including "a success must carry evidence".
        Validate::paymentBody($payment, 200, $this->redactor());

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
}
