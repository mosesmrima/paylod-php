<?php

declare(strict_types=1);

namespace Paylod\Http;

use Paylod\Exceptions\PaylodConnectionError;

/**
 * The single HTTP seam the SDK depends on - the PHP analogue of the Node client's injectable
 * `fetch`. The default is {@see CurlTransport}; tests inject a fake that returns canned responses
 * with no network. Wrapping any PSR-18 client is a few lines (see the README), but the SDK does
 * not require one, keeping runtime dependencies to the bundled ext-curl.
 */
interface Transport
{
    /**
     * Perform one HTTP request.
     *
     * @param array<string,string> $headers carries the Authorization Bearer key; implementations
     *   SHOULD mark it #[\SensitiveParameter] so the secret is scrubbed from stack traces.
     * @return array{status:int, headers:array<string,string>, body:string}
     *
     * @throws PaylodConnectionError on any transport-level failure (DNS, TLS, socket, timeout).
     */
    public function send(string $method, string $url, #[\SensitiveParameter] array $headers, ?string $body, int $timeoutMs): array;
}
