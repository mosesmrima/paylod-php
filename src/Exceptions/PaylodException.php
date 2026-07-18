<?php

declare(strict_types=1);

namespace Paylod\Exceptions;

/**
 * Base class - `catch (PaylodException $e)` catches every error this SDK throws.
 *
 * DESIGN RULE: a *payment* that fails (wrong PIN, cancelled, low balance) is NOT thrown - it is
 * an expected business outcome, returned as a renderable PaymentOutcome from collectAndWait()
 * with status "failed" and a customer-facing message. Everything in this namespace is a
 * *programmer, transport, or indeterminate* problem: the kinds of thing you genuinely want to
 * blow up a request handler.
 */
class PaylodException extends \Exception
{
    /**
     * The effective `Idempotency-Key` of the request that produced this error, when one is known.
     *
     * A failed `collect()` (network, timeout, 5xx, malformed 2xx) attaches the key it used so the
     * caller can retry with the SAME key rather than mint a fresh one and double-charge.
     */
    public ?string $idempotencyKey = null;

    /**
     * The payment this error concerns, when one had already been acknowledged.
     *
     * Set alongside {@see $idempotencyKey} when a `collectAndWait()` fails AFTER the STK push was
     * accepted: at that point a payment exists, and the caller needs its id to read the real
     * outcome rather than start a second charge.
     */
    public ?string $paymentId = null;

    /**
     * Attach the effective idempotency key, but NEVER clobber one an error already carries. Mirrors
     * the Node SDK's `attachIdempotencyKey` best-effort semantics.
     */
    public function attachIdempotencyKey(string $key): void
    {
        if ($this->idempotencyKey === null) {
            $this->idempotencyKey = $key;
        }
    }

    /** Attach the acknowledged payment id, never clobbering one the error already carries. */
    public function attachPaymentId(string $paymentId): void
    {
        if ($this->paymentId === null && $paymentId !== '') {
            $this->paymentId = $paymentId;
        }
    }

    /**
     * OVERWRITE both fields with the acknowledged payment's AUTHORITATIVE context. Unconditional,
     * on purpose.
     *
     * `attach*()` is best-effort and never clobbers, which is right when the error arose from the
     * request that owns the key. It is WRONG once a payment has been acknowledged. Past that point
     * the caller is holding a live payment, and the key and id that identify it are facts, not
     * suggestions - but the error is often not from that request at all. An `onPoll` callback can
     * throw a `PaylodException` it constructed itself, or one it caught earlier from an unrelated
     * charge; a stale error object can be rethrown. Under `attach*()` that error's PRE-EXISTING key
     * or payment id survived, and the caller then read the wrong payment, decided nothing had
     * happened, and re-charged the customer under a fresh key.
     *
     * So the acknowledgement wins. There is exactly one payment this error can be about.
     */
    public function bindToAcknowledgedPayment(string $idempotencyKey, string $paymentId): void
    {
        $this->idempotencyKey = $idempotencyKey;
        $this->paymentId = $paymentId !== '' ? $paymentId : null;
    }
}
