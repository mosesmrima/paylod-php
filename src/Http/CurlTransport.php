<?php

declare(strict_types=1);

namespace Paylod\Http;

use Paylod\Exceptions\PaylodConnectionError;

/**
 * The default transport: a thin wrapper over ext-curl. No third-party HTTP client required.
 *
 * Retries, idempotency, backoff and error mapping all live in the client; this class does exactly
 * one thing - send the bytes and hand back status + headers + body, or throw a
 * {@see PaylodConnectionError} if the socket never got that far.
 */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new PaylodConnectionError('Could not initialise a cURL handle.');
        }

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
            curl_close($ch);
            throw new PaylodConnectionError("Could not reach paylod at {$url}: {$err}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => (string) $raw,
        ];
    }
}
