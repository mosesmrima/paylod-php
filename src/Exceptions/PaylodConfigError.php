<?php

declare(strict_types=1);

namespace Paylod\Exceptions;

/** Configuration problem - e.g. no API key supplied and PAYLOD_API_KEY is unset. */
class PaylodConfigError extends PaylodException
{
}
