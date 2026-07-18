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
}
