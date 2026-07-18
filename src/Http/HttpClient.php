<?php

declare(strict_types=1);

namespace Paylod\Http;

use Paylod\Exceptions\PaylodConnectionError;

/**
 * The LOW-LEVEL byte mover. This is NOT the SDK's dispatch seam - {@see Transport} is, and it is a
 * final class that cannot be replaced.
 *
 * -- Read this before implementing one -------------------------------------------------------
 * An implementation of this interface RECEIVES THE AUTHORIZATION HEADER. That is a bearer
 * credential: whoever holds it can move money. Which is precisely why supplying your own is a
 * GATED, TEST-ONLY seam - it requires an explicit `allowCustomHttpClient: true` opt-in and is
 * refused outright for `mp_live_` keys, at the client AND again inside {@see Transport}. The
 * default is {@see CurlHttpClient} and in production it is the only implementation that ever runs.
 *
 * An implementation MUST NOT follow redirects. {@see Transport} refuses redirects on its own terms
 * as well (3xx status, a non-zero redirect count, and an off-origin effective URL), but a client
 * that follows one has ALREADY replayed the credential to another host - detection after the fact
 * is not prevention. Prevention is that a live key never reaches caller code at all.
 */
interface HttpClient
{
    /**
     * Perform one HTTP request. No retries, no idempotency, no error mapping - those live in the
     * client. Send the bytes, hand back status + headers + body.
     *
     * @param array<string,string> $headers carries the Authorization Bearer key; implementations
     *   SHOULD mark it #[\SensitiveParameter] so the secret is scrubbed from stack traces.
     * @return array{
     *   status:int,
     *   headers:array<string,string>,
     *   body:string,
     *   effectiveUrl?:?string,
     *   redirectCount?:?int
     * } `effectiveUrl` and `redirectCount` are OPTIONAL but strongly encouraged: they are what lets
     *   {@see Transport} detect a redirect that was followed rather than surfaced. Omitting them
     *   does not weaken the status-code check.
     *
     * @throws PaylodConnectionError on any transport-level failure (DNS, TLS, socket, timeout).
     */
    public function send(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers,
        ?string $body,
        int $timeoutMs,
    ): array;
}
