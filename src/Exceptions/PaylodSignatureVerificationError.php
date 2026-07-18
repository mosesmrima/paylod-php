<?php

declare(strict_types=1);

namespace Paylod\Exceptions;

/** A webhook request could not be verified. Respond 400 and do not process the body. */
class PaylodSignatureVerificationError extends PaylodException
{
    /**
     * One of: missing_signature | malformed_signature | stale_timestamp | no_match | invalid_payload
     * | insecure_tolerance (a non-positive toleranceSec was used outside a fixed-clock test, which
     * would have silently disabled replay protection).
     */
    public readonly string $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }
}
