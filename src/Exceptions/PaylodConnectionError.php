<?php

declare(strict_types=1);

namespace Paylod\Exceptions;

/** The request could not be completed at the transport layer (DNS, TLS, socket, timeout). */
class PaylodConnectionError extends PaylodException
{
}
