<?php

declare(strict_types=1);

namespace Paylod\Exceptions;

/** Bad input caught locally, before any network call (invalid amount, unparseable phone). */
class PaylodInvalidRequestError extends PaylodException
{
}
