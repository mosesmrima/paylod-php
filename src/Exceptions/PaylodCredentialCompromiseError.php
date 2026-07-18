<?php

declare(strict_types=1);

namespace Paylod\Exceptions;

/**
 * A DETECTED CREDENTIAL COMPROMISE. Terminal, and deliberately NOT a `PaylodConnectionError`.
 *
 * -- Why this class exists ---------------------------------------------------------------------
 * The redirect and off-origin detections in {@see \Paylod\Http\Transport} used to throw
 * `PaylodConnectionError`. The retry loop in {@see \Paylod\Paylod::request()} catches exactly that
 * type and treats it as a network blip, so every one of these detections was RETRIED - three
 * dispatches with the default `maxRetries`. The consequences run in the wrong direction on both
 * counts:
 *
 *   - On a followed redirect, the bearer key has ALREADY been replayed to another host. Retrying
 *     replays it twice more, to the same attacker, after the SDK has positively identified the
 *     event. The one thing a compromise detection must never do is repeat the exposure.
 *   - On `/collect`, each retry is another POSTED CHARGE. A misconfigured redirect in front of the
 *     API turned one payment into three attempts.
 *
 * A redirect is a configuration error or an attack. It is never transient, so there is no reading
 * under which trying again is the right move, and "not retryable" has to be a property of the TYPE
 * rather than a rule the retry loop is trusted to remember. The JVM SDK fixed this the same way; the
 * loop catches `PaylodConnectionError` and this class is not one, so it can never be swallowed
 * there - a future `catch` broadening that would have to name this type explicitly.
 *
 * It extends {@see PaylodException}, so `catch (PaylodException $e)` still catches it and
 * `collect()` still attaches the effective idempotency key on the way out.
 */
final class PaylodCredentialCompromiseError extends PaylodException
{
}
