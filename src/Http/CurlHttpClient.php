<?php

declare(strict_types=1);

namespace Paylod\Http;

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

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            // NEVER auto-follow. A 3xx to another host would put the bearer key on that host.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            // Certificate and hostname verification are cURL's defaults; pinned explicitly so a
            // php.ini or a distro build with laxer defaults cannot silently downgrade TLS.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                $idx = strpos($line, ':');
                if ($idx !== false) {
                    $key = strtolower(trim(substr($line, 0, $idx)));
                    $responseHeaders[$key] = trim(substr($line, $idx + 1));
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
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
            'body' => (string) $raw,
            // Reported so Transport can prove, independently of this class, that nothing was
            // followed - see Transport::assertNotRedirected().
            'effectiveUrl' => is_string($effectiveUrl) && $effectiveUrl !== '' ? $effectiveUrl : null,
            'redirectCount' => is_int($redirectCount) ? $redirectCount : null,
        ];
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
