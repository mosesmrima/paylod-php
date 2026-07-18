<?php

declare(strict_types=1);

namespace Paylod\Exceptions;

/**
 * A simulator call was made with a key that is not a sandbox (mp_test_) key.
 *
 * Thrown LOCALLY, before any request leaves the process - the key's own prefix is enough to know.
 * The backend refuses a live key too, but a "simulate" call that can even *attempt* to reach
 * production is a footgun; this makes it structurally impossible.
 *
 * It extends PaylodConfigError because that is what it is: the wrong credential, not a transient
 * failure. Retrying will never help.
 */
class PaylodSandboxOnlyError extends PaylodConfigError
{
}
