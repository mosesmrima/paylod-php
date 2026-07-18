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
     * Attach the effective idempotency key, but NEVER clobber one an error already carries. Mirrors
     * the Node SDK's `attachIdempotencyKey` best-effort semantics.
     */
    public function attachIdempotencyKey(string $key): void
    {
        if ($this->idempotencyKey === null) {
            $this->idempotencyKey = $key;
        }
    }
}
