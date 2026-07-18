<?php

declare(strict_types=1);

namespace Paylod\Http;

use Closure;
use Paylod\Exceptions\PaylodConfigError;
use Paylod\Exceptions\PaylodCredentialCompromiseError;
use Paylod\Support\Redact;

/**
 * THE CREDENTIALED TRANSPORT.
 *
 * -- Why this is a final class and not an interface -------------------------------------------
 * The API key is a BEARER credential: whoever receives it can move money. The previous design made
 * `Transport` an INTERFACE and handed the fully-built `Authorization: Bearer ...` header to an
 * arbitrary, caller-supplied implementation. Everything after that point was a suggestion rather
 * than a control: an injected transport is free to follow a cross-origin 302 itself and hand back
 * a perfectly ordinary 200 from https://evil.example, and by then the credential has ALREADY been
 * replayed. It is also free to put the header into its own exception traces. Checking afterwards is
 * too late.
 *
 * So the credential no longer crosses a replaceable boundary at all:
 *
 *   1. THE KEY LIVES HERE. Callers pass a method, a path and a body. They never see the key, never
 *      construct headers, never supply a URL and never choose a redirect mode - so they have no way
 *      to address the credential anywhere.
 *   2. THE DISPATCH IS SDK-OWNED. The implementation is {@see CurlHttpClient}. It can be swapped
 *      ONLY through the explicit, test-only opt-in below, which is refused for `mp_live_` keys -
 *      the same posture `allowInsecureBaseUrl` already had. That refusal is enforced by the client
 *      AND repeated here, so this class holds the line on its OWN terms and cannot be misused by a
 *      future caller.
 *   3. THE ORIGIN IS PINNED, per dispatch. Not once at construction: the origin of the URL actually
 *      being requested is recomputed and compared every single time.
 *   4. REDIRECTS ARE REFUSED, not followed and then judged - and the refusal is layered, so a
 *      client that lies about not following one is still caught. See assertNotRedirected().
 *
 * Points 3 and 4 run INSIDE this class, on every dispatch, with no way for a caller to opt out.
 * That is the difference between a protection and a suggestion.
 */
final class Transport
{
    /**
     * The ONLY origins a live bearer key may be sent to. HTTPS alone is not enough: any
     * attacker-controlled https:// host would happily accept the Authorization header and replay it.
     *
     * @var list<string>
     */
    public const ALLOWED_HOSTS = ['paylod.dev', 'api.paylod.dev'];

    /** Hosts that may be used with the explicit, test-only insecure opt-in. */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    private const LIVE_PREFIX = 'mp_live_';

    /** The credential. Held in a closure so `var_export()` cannot reach it - see Paylod::__debugInfo(). */
    private Closure $apiKey;

    private string $baseUrl;

    /** Derived ONCE from the validated base URL, then immutable. Every dispatch is checked against it. */
    private string $origin;

    private HttpClient $client;

    private Closure $redact;

    /**
     * @param Closure():string $apiKey the credential, behind a closure
     * @param string $baseUrl already normalised (no trailing slash) and already passed
     *   {@see self::assertSecureBaseUrl()}
     * @param Closure(string):string $redact scrubs the key/secret out of anything that could be
     *   logged or thrown
     * @param ?HttpClient $testClient TEST ONLY. Replaces the dispatch implementation. Refused for
     *   `mp_live_` keys by {@see \Paylod\Paylod}'s constructor before a Transport is ever built; the
     *   assertion is repeated here because a transport that would hand a production key to
     *   caller-supplied code under ANY circumstance is not a transport worth having.
     */
    public function __construct(
        Closure $apiKey,
        string $baseUrl,
        Closure $redact,
        ?HttpClient $testClient = null,
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->redact = $redact;
        $this->origin = self::originOf($baseUrl)
            ?? throw new PaylodConfigError('baseUrl has no usable origin.');

        if ($testClient !== null) {
            // Belt and braces: Paylod refuses this combination before constructing us.
            if (str_starts_with(($apiKey)(), self::LIVE_PREFIX)) {
                throw new PaylodConfigError(
                    'A custom HTTP client may never be used with an mp_live_ key. The API key is a '
                    . 'bearer credential, and a caller-supplied client receives it on every request. '
                    . 'Use an mp_test_ key for tests that need to stub the transport.'
                );
            }
            $this->client = $testClient;
        } else {
            $this->client = new CurlHttpClient();
        }
    }

    /**
     * Dispatch one credentialed request.
     *
     * The caller supplies a method, a path and a body. It does NOT supply headers, a URL, a redirect
     * mode, or the credential - all four are produced here, which is precisely what makes the
     * guarantees below unconditional.
     *
     * @param array<string,mixed>|null $body
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function send(
        string $method,
        string $path,
        mixed $body = null,
        ?string $idempotencyKey = null,
        int $timeoutMs = 30000,
    ): array {
        $url = $this->baseUrl . $path;
        // Recomputed EVERY dispatch, so no path can ever walk the request off-origin.
        $this->assertOnOrigin($url, 'the request URL');

        $headers = [
            // THE credential. Constructed here, from a private closure, on every request.
            'Authorization' => 'Bearer ' . ($this->apiKey)(),
            'Accept' => 'application/json',
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
        }
        // Sent on every mutating call - this is what makes a retry safe.
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $res = $this->client->send($method, $url, $headers, $payload, $timeoutMs);

        $this->assertNotRedirected($res, $url);

        return [
            'status' => (int) ($res['status'] ?? 0),
            'headers' => is_array($res['headers'] ?? null) ? $res['headers'] : [],
            'body' => (string) ($res['body'] ?? ''),
        ];
    }

    /** The pinned origin, for diagnostics and tests. */
    public function origin(): string
    {
        return $this->origin;
    }

    /** The dispatch implementation's class name, for diagnostics. Never the credential. */
    public function clientClass(): string
    {
        return get_class($this->client);
    }

    /** Masked rendering - this object holds the credential, and it hangs off the client. */
    public function __debugInfo(): array
    {
        return ['origin' => $this->origin, 'client' => $this->clientClass()];
    }

    /**
     * Refuse anything that IS, or CAME FROM, a redirect - in three independent ways, because the
     * dispatch implementation is the thing we are defending against and it cannot be trusted to
     * report honestly:
     *
     *   1. A 3xx status - a redirect that was surfaced rather than followed. Always available.
     *   2. A non-zero redirect count - the implementation FOLLOWED one despite being told not to.
     *      This is the injected-client attack: the credential has already been replayed, so this is
     *      a DETECTION, not a prevention. It is here so the failure is loud rather than silent and
     *      so the caller learns their key is burned. Prevention is that a live key can never reach
     *      a custom client in the first place.
     *   3. An effective URL off the pinned origin - which catches an implementation that follows a
     *      redirect while lying about (or omitting) the count.
     *
     * None of these is retryable: a redirect is a configuration error or an attack, never a blip.
     * That is enforced BY THE TYPE. Each throws {@see PaylodCredentialCompromiseError}, which is
     * NOT a `PaylodConnectionError` and therefore cannot be swallowed by the retry loop in
     * {@see \Paylod\Paylod::request()}. Previously these were connection errors, so a detected
     * compromise was retried: the bearer key was replayed to the attacker twice more, and on
     * `/collect` each retry was another posted charge.
     *
     * @param array<string,mixed> $res
     */
    private function assertNotRedirected(array $res, string $requested): void
    {
        $status = (int) ($res['status'] ?? 0);
        if ($status >= 300 && $status < 400) {
            throw new PaylodCredentialCompromiseError($this->scrub(
                "paylod returned an unexpected redirect (HTTP {$status}) from {$requested}. Refusing "
                . 'to follow it - a cross-origin redirect could leak your Authorization header to '
                . 'another host.'
            ));
        }

        $redirectCount = $res['redirectCount'] ?? null;
        if (is_int($redirectCount) && $redirectCount > 0) {
            throw new PaylodCredentialCompromiseError($this->scrub(
                'The HTTP client FOLLOWED ' . $redirectCount . ' redirect(s) even though this SDK '
                . 'disabled redirect following. Your Authorization header may already have been '
                . 'replayed to another host - treat this API key as compromised and rotate it. This '
                . 'is only reachable through the test-only custom HTTP client option, which is why '
                . 'that option is refused for mp_live_ keys.'
            ));
        }

        // An empty/absent effective URL is not evidence of anything (a stubbed client has none) and
        // must not be treated as a violation.
        $effective = $res['effectiveUrl'] ?? null;
        if (is_string($effective) && $effective !== '') {
            $this->assertOnOrigin($effective, 'the responding URL');
        }
    }

    private function assertOnOrigin(string $candidate, string $what): void
    {
        $origin = self::originOf($candidate);
        if ($origin === null) {
            throw new PaylodCredentialCompromiseError($this->scrub("{$what} is not a valid URL."));
        }
        if ($origin !== $this->origin) {
            throw new PaylodCredentialCompromiseError($this->scrub(
                "Refusing a request that is not addressed to the pinned paylod origin: {$what} "
                . "resolves to \"{$origin}\", but this client is pinned to \"{$this->origin}\". Your "
                . 'API key is a bearer credential and is sent on every request, so it may only ever '
                . 'be addressed to the origin it was configured for.'
            ));
        }
    }

    private function scrub(string $message): string
    {
        return ($this->redact)($message);
    }

    /**
     * The canonical `scheme://host[:port]` of a URL, lowercased, with the DEFAULT PORT MADE
     * EXPLICIT. Without that normalisation `https://paylod.dev` and `https://paylod.dev:443` would
     * compare unequal and a legitimate response would be refused - or, worse, a future change could
     * make them compare equal by dropping the port entirely, which would let `:8443` through.
     */
    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null));
        if ($port === null) {
            return null;
        }

        return "{$scheme}://{$host}:{$port}";
    }

    /**
     * Enforce a secure, ALLOWLISTED origin for baseUrl before the API key can leave the process.
     *
     * HTTPS is necessary but nowhere near sufficient - a bearer key posted to https://evil.example
     * is just as stolen as one posted over plaintext. So the host itself must be the canonical
     * paylod production origin. We additionally reject anything that smuggles a different effective
     * target past a naive eyeball check: userinfo (https://paylod.dev@evil.example), a missing host,
     * a non-default port, a query string or fragment, and private / loopback / link-local IPs.
     *
     * Loopback is permitted ONLY behind the explicit test-only opt-in, and NEVER with a live
     * (mp_live_) key. The SCHEME is checked before that opt-in can return, so the opt-in relaxes the
     * ORIGIN rule and nothing else - it must never wave through ftp://, ws:// or file://.
     */
    public static function assertSecureBaseUrl(
        #[\SensitiveParameter] string $baseUrl,
        #[\SensitiveParameter] string $apiKey,
        bool $allowInsecure,
    ): void {
        // THE BASE URL IS A SECRET-BEARING STRING, and every message below interpolates it.
        //
        // It was neither marked sensitive nor redacted, so a userinfo section - the very thing the
        // first check exists to reject - put its contents into the exception message AND into the
        // stack trace: `https://mp_live_realkey@paylod.dev/...` was refused with the live key quoted
        // verbatim in the text a caller logs. The check was right and the diagnostic leaked the
        // credential it caught.
        //
        // So a REDACTED rendering is computed once, up front, and used in every message. The
        // original is still what gets PARSED - redaction must not change what is being validated,
        // only what is said about it.
        $shown = Redact::text($baseUrl, [$apiKey]);
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme']) || !isset($parts['host']) || $parts['host'] === '') {
            throw new PaylodConfigError("baseUrl is not a valid absolute URL: \"{$shown}\".");
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = $parts['port'] ?? null;
        $isLive = str_starts_with($apiKey, self::LIVE_PREFIX);

        // Credentials in the URL are never legitimate here, and `https://paylod.dev@evil.example`
        // reads as the real origin while resolving to the attacker's.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new PaylodConfigError(
                "baseUrl must not contain credentials (got \"{$shown}\"). A userinfo section makes the "
                . 'URL read like the paylod origin while pointing somewhere else entirely.'
            );
        }
        // The base URL is a prefix we concatenate paths onto - a query or fragment would be silently
        // relocated into the middle of the request line.
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new PaylodConfigError(
                "baseUrl must not contain a query string or fragment (got \"{$shown}\")."
            );
        }

        $isLoopbackHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || in_array($host, self::LOOPBACK_HOSTS, true);

        // THE SCHEME IS CHECKED FIRST, before the loopback opt-in can return. Checking it afterwards
        // meant the opt-in did not merely relax the ORIGIN rule - it waved through any scheme at all
        // (ftp://127.0.0.1/, ws://localhost/, file://localhost/), and the client would hand a bearer
        // key to whatever the transport made of them.
        if ($scheme !== 'https' && !($scheme === 'http' && $isLoopbackHost && $allowInsecure && !$isLive)) {
            throw new PaylodConfigError(
                "baseUrl must use https:// (got scheme \"{$scheme}\" in \"{$shown}\"). Plaintext HTTP "
                . 'would transmit your API key in the clear, and any other scheme (ftp, ws, file, '
                . 'data...) is not something this SDK will ever speak. Loopback HTTP is allowed ONLY '
                . "with ['allowInsecureBaseUrl' => true] and NEVER with an mp_live_ key."
            );
        }

        // The sanctioned test escape hatch: an explicit opt-in, a loopback host, and never a live key.
        if ($isLoopbackHost) {
            if ($allowInsecure && !$isLive) {
                return;
            }
            throw new PaylodConfigError(
                "baseUrl points at loopback (\"{$shown}\"). That is allowed ONLY with "
                . "['allowInsecureBaseUrl' => true] and NEVER with an mp_live_ key."
            );
        }

        // A bare IP literal is never the paylod origin, and private / link-local ranges are the
        // classic SSRF pivot (169.254.169.254 and friends).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new PaylodConfigError(
                "baseUrl must name the paylod host, not a raw IP address (got \"{$shown}\")."
            );
        }

        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new PaylodConfigError(
                "baseUrl origin \"{$host}\" is not a paylod origin. Your API key can move money, so it is "
                . 'only ever sent to ' . implode(' or ', self::ALLOWED_HOSTS) . '. Remove the custom '
                . 'baseUrl / PAYLOD_BASE_URL override.'
            );
        }

        if ($port !== null && (int) $port !== 443) {
            throw new PaylodConfigError(
                "baseUrl must use the default https port (got port {$port} in \"{$shown}\")."
            );
        }
    }
}
