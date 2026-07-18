<?php

declare(strict_types=1);

namespace Paylod\Http;

use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodConnectionError;

/**
 * The SDK's own byte mover: a thin wrapper over ext-curl, and the only implementation that runs in
 * production. No third-party HTTP client required.
 *
 * Redirects are DISABLED here (CURLOPT_FOLLOWLOCATION => false) rather than merely inspected
 * afterwards. Following a cross-origin 3xx would replay the Authorization header to another host,
 * and a check performed after the replay is a post-mortem, not a control. The redirect count and
 * the effective URL are reported back so {@see Transport} can additionally prove none was followed.
 */
final class CurlHttpClient implements HttpClient
{
    /**
     * The hard ceiling on a response body, in bytes. 8 MiB.
     *
     * `CURLOPT_RETURNTRANSFER` buffers the WHOLE response in memory with no bound, so the size of
     * the allocation was chosen entirely by whoever was on the other end of the socket. A
     * compromised or merely broken endpoint - or anything that can MITM a connection - answering a
     * `/collect` with an endless body drove the process into the memory limit and killed it. That
     * is not a denial of service on a status page: it happens AFTER the charge has been dispatched,
     * so the process dies without ever learning the payment id or recording the outcome, and the
     * natural recovery (run it again) charges the customer a second time.
     *
     * 8 MiB is orders of magnitude larger than any real paylod response (they are small JSON
     * objects); it is a backstop, not a tuning knob.
     */
    public const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    /**
     * The ceiling on the AGGREGATE response headers, in bytes. 256 KiB.
     *
     * The body was bounded and the headers were not. libcurl caps each individual header at 100 KiB
     * and hands them to the callback one at a time, which looks like a bound but is not one: nothing
     * limited HOW MANY arrived, and every one of them was accumulated into `$responseHeaders`
     * forever. A peer answering with an endless stream of distinct header names drove the process
     * into the memory limit by exactly the route the body ceiling exists to close - and with the
     * same consequence, because it happens AFTER the collect has been dispatched. The process dies
     * without learning the payment id, and the natural recovery charges the customer again.
     *
     * A per-header cap alone would not fix it either, so BOTH the aggregate byte count and the
     * header COUNT are tracked: 2000 tiny headers cost as much as one enormous one.
     *
     * 256 KiB and 200 headers are orders of magnitude beyond any real paylod response. Backstops,
     * not tuning knobs.
     */
    public const MAX_HEADER_BYTES = 256 * 1024;

    /** The ceiling on the NUMBER of response headers. */
    public const MAX_HEADER_COUNT = 200;

    /**
     * @param array<string,string> $headers marked #[\SensitiveParameter] so the Authorization
     *   Bearer key is scrubbed from any stack trace this frame appears in - PHP renders a sensitive
     *   argument as an opaque placeholder rather than dumping the secret into exception traces/logs.
     */
    public function send(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers,
        ?string $body,
        int $timeoutMs,
    ): array {
        $ch = curl_init();
        if ($ch === false) {
            throw new PaylodConnectionError('Could not initialise a cURL handle.');
        }

        // The bearer token, kept only to scrub it out of any transport error text we surface.
        $bearer = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $responseHeaders = [];
        $headerBytes = 0;
        $headerOverflowed = false;

        // The BOUNDED buffer. Body bytes are accumulated by hand rather than by
        // CURLOPT_RETURNTRANSFER so the ceiling is enforced AS THEY ARRIVE: the moment the limit is
        // passed the write callback returns a short count, which makes cURL abort the transfer and
        // tear down the connection. Nothing beyond the ceiling is ever allocated, so the peer
        // cannot choose how much memory this process spends.
        $buffer = '';
        $bytes = 0;
        $overflowed = false;

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$buffer, &$bytes, &$overflowed): int {
                $accepted = self::acceptChunk($buffer, $bytes, $chunk);
                if ($accepted !== strlen($chunk)) {
                    $overflowed = true;
                }

                return $accepted;
            },
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            // NEVER auto-follow. A 3xx to another host would put the bearer key on that host.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            // Certificate and hostname verification are cURL's defaults; pinned explicitly so a
            // php.ini or a distro build with laxer defaults cannot silently downgrade TLS.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders, &$headerBytes, &$headerOverflowed): int {
                // BOUNDED THE SAME WAY THE BODY IS: the moment the aggregate ceiling or the header
                // count is passed, return a short count. That is cURL's "abort now" signal, so the
                // transfer is torn down and nothing further is ever allocated.
                if (!self::acceptHeader($responseHeaders, $headerBytes, $line)) {
                    $headerOverflowed = true;

                    return 0;
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);

        // The overflow is checked BEFORE the generic transport-failure branch, because an aborted
        // write makes curl_exec() return false and the generic branch would misreport it as a
        // network blip - which request() RETRIES. Re-POSTing a charge because the response was too
        // big is exactly the double-charge this SDK exists to prevent. So it is raised as a KEYED,
        // INDETERMINATE PaylodApiError instead: not a connection error, therefore not retried, and
        // collect() attaches the effective idempotency key to it on the way out.
        if ($overflowed) {
            throw self::overflowError();
        }

        // The SAME keyed, indeterminate error as a body overflow, and for the same reason: the
        // request WAS sent, so this must never be reported as a retryable network blip.
        if ($headerOverflowed) {
            throw self::overflowError(
                'response headers larger than the ' . self::MAX_HEADER_BYTES . '-byte / '
                . self::MAX_HEADER_COUNT . '-header ceiling'
            );
        }

        if ($raw === false) {
            $err = curl_error($ch);
            // Rethrow SANITIZED: scrub the bearer token out of the message in case a URL/proxy error
            // ever echoes a header back. (curl_close() is intentionally omitted - the CurlHandle is
            // freed automatically when it goes out of scope, and curl_close() warns on PHP 8.5+.)
            throw new PaylodConnectionError(self::redact("Could not reach paylod at {$url}: {$err}", $bearer));
        }

        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $redirectCount = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);

        return [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'headers' => $responseHeaders,
            // The hand-accumulated buffer, not curl_exec()'s return value: with a WRITEFUNCTION
            // set, cURL hands the bytes to the callback and returns true rather than the body.
            'body' => $buffer,
            // Reported so Transport can prove, independently of this class, that nothing was
            // followed - see Transport::assertNotRedirected().
            'effectiveUrl' => is_string($effectiveUrl) && $effectiveUrl !== '' ? $effectiveUrl : null,
            'redirectCount' => is_int($redirectCount) ? $redirectCount : null,
        ];
    }

    /**
     * THE CEILING ITSELF, as a pure function of the buffer so far and one arriving chunk.
     *
     * Split out of the write callback so it can be driven directly by a test - a ceiling that is
     * only reachable by standing up a server that streams gigabytes is a ceiling nobody verifies.
     *
     * @return int the number of bytes accepted. Anything less than `strlen($chunk)` is cURL's
     *   documented "abort the transfer now" signal, and here it is always 0: partial acceptance
     *   would hand the caller a TRUNCATED body, which is worse than no body at all, because a
     *   truncated JSON payment record either fails to parse or - far worse - parses into a
     *   different record than the one the server sent.
     */
    private static function acceptChunk(string &$buffer, int &$bytes, string $chunk): int
    {
        $len = strlen($chunk);
        if ($bytes + $len > self::MAX_RESPONSE_BYTES) {
            return 0;
        }
        $buffer .= $chunk;
        $bytes += $len;

        return $len;
    }

    /**
     * THE HEADER CEILING, as a pure function of the headers so far and one arriving line.
     *
     * Split out of the header callback for the same reason {@see acceptChunk()} is: a ceiling that
     * can only be reached by standing up a server that emits thousands of headers is a ceiling
     * nobody verifies.
     *
     * The aggregate byte count is charged for EVERY line, including continuation lines and lines
     * with no colon, because those cost memory to receive whether or not they are stored. The count
     * ceiling is applied to the number of DISTINCT stored headers - repeating one name over and over
     * overwrites rather than grows, and is already covered by the byte ceiling.
     *
     * @param array<string,string> $headers
     * @return bool false when the line must be refused, which aborts the transfer
     */
    private static function acceptHeader(array &$headers, int &$headerBytes, string $line): bool
    {
        $headerBytes += strlen($line);
        if ($headerBytes > self::MAX_HEADER_BYTES) {
            return false;
        }

        $idx = strpos($line, ':');
        if ($idx === false) {
            return true; // the status line and the trailing CRLF - counted, not stored
        }

        $key = strtolower(trim(substr($line, 0, $idx)));
        if (!isset($headers[$key]) && count($headers) >= self::MAX_HEADER_COUNT) {
            return false;
        }
        $headers[$key] = trim(substr($line, $idx + 1));

        return true;
    }

    /**
     * The overflow error. Deliberately a KEYED, INDETERMINATE `PaylodApiError` and NOT a
     * `PaylodConnectionError`: the client RETRIES connection errors, and re-POSTing a charge
     * because the response was too big is precisely the double-charge this SDK exists to prevent.
     * `collect()` attaches the effective idempotency key to it on the way out.
     */
    private static function overflowError(?string $what = null): PaylodApiError
    {
        return new PaylodApiError(
            'paylod returned ' . ($what ?? 'a response larger than the ' . self::MAX_RESPONSE_BYTES
            . '-byte ceiling') . ' and the transfer was aborted. The request WAS sent, so the charge '
            . 'state is INDETERMINATE - the STK prompt may already be on the phone. Read the '
            . 'payment with the attached idempotencyKey before starting any new attempt; do NOT '
            . 'mint a fresh key (that risks a second charge).',
            0,
            null,
            null,
            true,
        );
    }

    /** Scrub the bearer token (and its bare key) out of a message before it is thrown/logged. */
    private static function redact(string $message, ?string $bearer): string
    {
        if ($bearer === null || $bearer === '') {
            return $message;
        }
        $out = str_replace($bearer, '[redacted]', $message);
        // Also redact the bare key without the "Bearer " prefix, if present.
        if (str_starts_with($bearer, 'Bearer ')) {
            $out = str_replace(substr($bearer, 7), '[redacted]', $out);
        }

        return $out;
    }
}
