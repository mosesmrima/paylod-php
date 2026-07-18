<?php

declare(strict_types=1);

namespace Paylod\Exceptions;

/** The API returned a non-2xx response. */
class PaylodApiError extends PaylodException
{
    /** HTTP status code. */
    public readonly int $status;

    /** The parsed JSON body, when the response had one. */
    public readonly mixed $body;

    /** `Idempotency-Key` sent with the offending request, if any - useful for support tickets. */
    public readonly ?string $idempotencyKey;

    public function __construct(string $message, int $status, mixed $body = null, ?string $idempotencyKey = null)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->body = $body;
        $this->idempotencyKey = $idempotencyKey;
    }

    /** 401 - the API key is missing or invalid. */
    public function isAuthError(): bool
    {
        return $this->status === 401;
    }

    /** 429 - you are being rate limited. Back off. */
    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    /** Any 409. Every 409 on a money-moving route comes from the idempotency layer. */
    public function isIdempotencyConflict(): bool
    {
        return $this->status === 409;
    }

    /**
     * 409 indeterminate - a previous request under this key died while the call to Daraja was in
     * flight, so it may or may not have moved money. paylod refuses to re-dispatch it: a timeout is
     * not evidence the money did not move, and for money at-most-once beats at-least-once.
     *
     * This is a STOP signal, not a retry signal. Read the payment status first, then, only if
     * nothing happened, open a NEW attempt with a NEW key.
     */
    public function isIdempotencyIndeterminate(): bool
    {
        return $this->status === 409
            && preg_match('/interrupted while the provider call was/i', $this->getMessage()) === 1;
    }

    /**
     * 409 in progress - the first request under this key is still running. Honour Retry-After and
     * retry the *same* key: you will get the winner's answer.
     */
    public function isIdempotencyInProgress(): bool
    {
        return $this->status === 409
            && preg_match('/already in progress/i', $this->getMessage()) === 1;
    }

    /**
     * 409 body conflict - the same Idempotency-Key was reused with a *different* body. This is
     * always a bug in your code (you changed the amount or the phone but kept the key): two
     * different charges collided on one key.
     */
    public function isIdempotencyBodyConflict(): bool
    {
        return $this->status === 409
            && !$this->isIdempotencyIndeterminate()
            && !$this->isIdempotencyInProgress();
    }
}
