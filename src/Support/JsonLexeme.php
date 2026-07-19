<?php

declare(strict_types=1);

namespace Paylod\Support;

/**
 * RAW-JSON GUARDS, applied BEFORE the parser is allowed to destroy the evidence.
 *
 * -- The problem this exists to solve ----------------------------------------------------------
 * {@see \Paylod\DarajaCatalog} refuses to read a non-canonical ResultCode: `-0`, `0e999`, `00` and
 * `+0` are impostors, not zeroes, and only the exact token `0` proves money moved. That rule is
 * correct and it is enforced on the value's LEXEME.
 *
 * By the time it runs, though, the lexeme is already gone. `json_decode('{"resultCode":-0}')`
 * yields the PHP integer `0` - not "-0", not a float, not a string: the identical value a genuine
 * `0` produces. The two are indistinguishable AFTER parsing, so a `status: "success"` body carrying
 * the raw token `-0` was classified EVIDENCE_SUCCESS and reported PAID. `0e999` decodes to the
 * float `0.0` by the same route. The classifier never had a chance; the parser had already
 * laundered the input.
 *
 * -- Why this is a PARSER and not a regex --------------------------------------------------------
 * The first version of this guard scanned the raw bytes for the literal characters `"resultCode"`.
 * That assumption - that a JSON member name appears in the bytes spelled the way it decodes - is
 * false. JSON permits `\uXXXX` in member names, so the SAME key can be written as
 * `"resultCode"`, `"result\u0043ode"`, `"\u0072esult\u0043\u006Fde"` and unboundedly many other
 * ways.
 * Every one of them decodes to `resultCode`, every one of them reached `json_decode()` unexamined,
 * and `{"resultCode":-0}` was therefore accepted as PAID on both the status path and the
 * signed-webhook path. The guard's logic was not wrong; its assumption about its input was.
 *
 * Adding more spellings to a pattern cannot fix that - the escape space is infinite. The only thing
 * that can is knowing BOTH what a member name decodes to AND which raw numeric token sat beside it.
 * So this class walks the document as an actual JSON scanner: it decodes every member name (escapes
 * included) and, for every member whose name decodes to exactly `resultCode` AT ANY DEPTH, it
 * inspects the ORIGINAL bytes of the value that followed. Duplicate keys are all seen -
 * `{"resultCode":1032,"resultCode":-0}` is refused on the second member regardless of which one
 * `json_decode()` would keep, because "which duplicate wins" is not a question a money guard should
 * ever have to answer correctly in order to be safe.
 *
 * -- Failing closed on divergence ----------------------------------------------------------------
 * A scanner that disagrees with `json_decode()` is itself an attack surface: anything this scanner
 * cannot read but PHP's parser CAN would slip through unexamined. So when the scan fails, the body
 * is handed to `json_decode()` as a cross-check. If PHP can read what this cannot, the body is
 * REFUSED outright ({@see self::UNREADABLE}) rather than waved through. If neither can read it, it
 * is not JSON at all and the callers' ordinary invalid-body handling takes over.
 *
 * -- Remaining, deliberate limits ----------------------------------------------------------------
 *
 *   1. It governs `resultCode` ONLY. That is the one field in the schema whose exact numeric
 *      lexeme decides whether money moved. Other numbers (amounts, timestamps) are not evidence and
 *      are not scanned.
 *   2. It says nothing about a resultCode sent as a JSON *string* (`"resultCode": " 0"`). It does
 *      not need to: a string survives `json_decode` byte-for-byte, so the lexeme reaches
 *      {@see \Paylod\DarajaCatalog} intact and is rejected there.
 *   3. A `resultCode` nested anywhere - inside `data`, inside an array, inside an unrelated
 *      sub-object - is scanned too. Refusing costs the caller a re-read; missing one costs a
 *      double-charge or an unpaid shipment.
 */
final class JsonLexeme
{
    /** The member name, DECODED, that this guard governs. */
    private const TARGET_KEY = 'resultCode';

    /** The only numeric ResultCode tokens the schema defines: exactly `0`, or an unsigned non-zero. */
    private const CANONICAL_TOKEN_RE = '/^(?:0|[1-9][0-9]*)\z/';

    /** JSON's own number grammar, applied to a greedily-captured numeric run. */
    private const JSON_NUMBER_RE = '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z/';

    /** Matches `json_decode()`'s own default nesting limit, so the two agree about depth. */
    public const MAX_DEPTH = 512;

    /**
     * The sentinel returned when this scanner cannot read a body that `json_decode()` can.
     *
     * It is a fixed string and never contains any attacker-supplied byte.
     */
    public const UNREADABLE = '<unreadable>';

    private int $i = 0;

    private int $len;

    /** The first non-canonical `resultCode` token found; set once, and the scan then unwinds. */
    private ?string $bad = null;

    private function __construct(private readonly string $raw)
    {
        $this->len = strlen($raw);
    }

    /**
     * The first non-canonical numeric `resultCode` token in the raw body, or null if there is none.
     *
     * Returned rather than thrown so each caller can raise the error its own surface requires - a
     * keyed indeterminate `PaylodApiError` on the money path, an `invalid_payload` signature error
     * on the webhook path.
     */
    public static function nonCanonicalResultCodeToken(string $raw, ?int $scanDepthLimit = null): ?string
    {
        $scan = new self($raw);
        // TEST-ONLY SEAM. The divergence branch below is the fail-closed cross-check, and it was
        // UNREACHABLE from a test: the scanner and `json_decode()` share MAX_DEPTH, so no ordinary
        // body makes one give up while the other succeeds - which meant the round-8 test for it
        // supplied only JSON both parsers reject, and would have passed just as happily if the whole
        // cross-check were deleted. Lowering the SCANNER's limit (and only the scanner's) produces a
        // genuine, controlled divergence, so the branch can be exercised and mutation-tested.
        // Never narrows in production: callers pass nothing, and a limit above MAX_DEPTH is ignored.
        if ($scanDepthLimit !== null && $scanDepthLimit < self::MAX_DEPTH) {
            $scan->depthLimit = max(0, $scanDepthLimit);
        }

        try {
            $scan->parseDocument();
        } catch (\RuntimeException) {
            if ($scan->bad !== null) {
                return $scan->bad;
            }

            // DIVERGENCE CHECK. This scanner gave up. If PHP's parser would NOT have, then there is
            // a document reaching `json_decode()` that was never examined - refuse it instead.
            json_decode($raw, true, self::MAX_DEPTH, JSON_BIGINT_AS_STRING);

            return json_last_error() === JSON_ERROR_NONE ? self::UNREADABLE : null;
        }

        return $scan->bad;
    }

    /**
     * A human explanation of why a raw token was refused, shared by both callers so the money path
     * and the webhook path describe the same defect the same way.
     */
    public static function explain(string $token): string
    {
        if ($token === self::UNREADABLE) {
            return 'the raw JSON decodes but could not be scanned for resultCode evidence, so no '
                . 'claim it makes about the result code can be trusted; a body whose structure the '
                . 'guard cannot read is refused rather than decoded';
        }

        return "the raw JSON carries a non-canonical resultCode token `{$token}`. The schema defines "
            . 'exactly one zero (`0`) and unsigned integers for everything else; `-0`, `0e999`, `00` '
            . 'and `+0` all parse to the very same value a genuine `0` does, so they cannot be told '
            . 'apart once decoded and are refused before decoding';
    }

    // -- the scan --------------------------------------------------------------------------------

    /** The effective scan depth ceiling. MAX_DEPTH unless a test narrows it. */
    private int $depthLimit = self::MAX_DEPTH;

    private function parseDocument(): void
    {
        $this->skipWhitespace();
        $this->parseValue(0);
        if ($this->bad !== null) {
            return;
        }
        $this->skipWhitespace();
        if ($this->i !== $this->len) {
            $this->fail(); // trailing content: `json_decode()` rejects it too
        }
    }

    private function parseValue(int $depth): void
    {
        if ($this->bad !== null) {
            return; // unwind: the verdict is already decided
        }
        if ($depth > $this->depthLimit) {
            $this->fail();
        }
        if ($this->i >= $this->len) {
            $this->fail();
        }

        $c = $this->raw[$this->i];

        if ($c === '{') {
            $this->parseObject($depth);

            return;
        }
        if ($c === '[') {
            $this->parseArray($depth);

            return;
        }
        if ($c === '"') {
            $this->parseString();

            return;
        }
        if ($c === 't' || $c === 'f' || $c === 'n') {
            if ($this->literal('true') || $this->literal('false') || $this->literal('null')) {
                return;
            }
            $this->fail();
        }
        if ($c === '-' || ($c >= '0' && $c <= '9')) {
            $this->readNumberToken(false);

            return;
        }

        $this->fail();
    }

    private function parseObject(int $depth): void
    {
        $this->expect('{');
        $this->skipWhitespace();
        if ($this->peek() === '}') {
            $this->i++;

            return;
        }

        while (true) {
            $this->skipWhitespace();
            if ($this->peek() !== '"') {
                $this->fail();
            }
            // The offset of the member name's OPENING QUOTE, so the raw bytes of the name are
            // recoverable beside its decoded form. Nothing in production reads it - the guard is
            // deliberately driven by the DECODED name - but keeping the raw span addressable is what
            // lets the mutation harness express the round-8 bug faithfully: "compare the literal
            // bytes instead of the decoded name" is a one-line revert rather than a rewrite of the
            // whole function, and a one-line revert cannot silently stop applying.
            $nameStart = $this->i;
            $name = $this->parseString();
            $this->skipWhitespace();
            $this->expect(':');
            $this->skipWhitespace();

            // THE ASSOCIATION THAT MAKES THIS A GUARD: the DECODED member name, next to the ORIGINAL
            // bytes of the value that follows it. Every duplicate is inspected, at every depth.
            $isTarget = $name === self::TARGET_KEY;
            $c = $this->peek();
            // `+` is not a legal JSON value start, but a `resultCode` beginning with one is an
            // impostor zero worth NAMING rather than dismissing as unparseable, so it is read here.
            if ($isTarget && ($c === '-' || $c === '+' || ($c !== null && $c >= '0' && $c <= '9'))) {
                $this->readNumberToken(true);
            } else {
                $this->parseValue($depth + 1);
            }

            if ($this->bad !== null) {
                return;
            }

            $this->skipWhitespace();
            $next = $this->peek();
            if ($next === ',') {
                $this->i++;
                continue;
            }
            if ($next === '}') {
                $this->i++;

                return;
            }
            $this->fail();
        }
    }

    private function parseArray(int $depth): void
    {
        $this->expect('[');
        $this->skipWhitespace();
        if ($this->peek() === ']') {
            $this->i++;

            return;
        }

        while (true) {
            $this->skipWhitespace();
            $this->parseValue($depth + 1);
            if ($this->bad !== null) {
                return;
            }
            $this->skipWhitespace();
            $next = $this->peek();
            if ($next === ',') {
                $this->i++;
                continue;
            }
            if ($next === ']') {
                $this->i++;

                return;
            }
            $this->fail();
        }
    }

    /**
     * Consume a JSON string and return its DECODED value - `\uXXXX`, surrogate pairs and the short
     * escapes all resolved, because a member name only means anything once it is decoded.
     */
    private function parseString(): string
    {
        $this->expect('"');
        $out = '';

        while (true) {
            if ($this->i >= $this->len) {
                $this->fail();
            }
            $c = $this->raw[$this->i];

            if ($c === '"') {
                $this->i++;

                return $out;
            }
            if ($c === '\\') {
                $this->i++;
                $out .= $this->readEscape();
                continue;
            }
            // Raw control characters are illegal inside a JSON string; `json_decode()` rejects them.
            if (ord($c) < 0x20) {
                $this->fail();
            }
            $out .= $c;
            $this->i++;
        }
    }

    private function readEscape(): string
    {
        if ($this->i >= $this->len) {
            $this->fail();
        }
        $e = $this->raw[$this->i++];

        switch ($e) {
            case '"': return '"';
            case '\\': return '\\';
            case '/': return '/';   // the legal-but-unusual escaped solidus
            case 'b': return "\x08";
            case 'f': return "\x0C";
            case 'n': return "\n";
            case 'r': return "\r";
            case 't': return "\t";
            case 'u': break;
            default: $this->fail();
        }

        $cp = $this->readHex4();

        // A high surrogate may be followed by a low surrogate; together they are one code point.
        if ($cp >= 0xD800 && $cp <= 0xDBFF
            && $this->i + 1 < $this->len
            && $this->raw[$this->i] === '\\'
            && $this->raw[$this->i + 1] === 'u'
        ) {
            $save = $this->i;
            $this->i += 2;
            $low = $this->readHex4();
            if ($low >= 0xDC00 && $low <= 0xDFFF) {
                $cp = 0x10000 + (($cp - 0xD800) << 10) + ($low - 0xDC00);
            } else {
                $this->i = $save; // not a pair; leave the second escape to the next iteration
            }
        }

        return self::utf8($cp);
    }

    private function readHex4(): int
    {
        if ($this->i + 4 > $this->len) {
            $this->fail();
        }
        $hex = substr($this->raw, $this->i, 4);
        if (preg_match('/^[0-9A-Fa-f]{4}\z/', $hex) !== 1) {
            $this->fail();
        }
        $this->i += 4;

        return (int) hexdec($hex);
    }

    /** Encode a code point as UTF-8; a lone surrogate becomes U+FFFD, exactly as a decoder must. */
    private static function utf8(int $cp): string
    {
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            return "\u{FFFD}";
        }
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }

        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    /**
     * Consume a numeric token GREEDILY - `+`, leading zeroes and stray exponents included.
     *
     * Capturing the WHOLE run is what lets `00`, `+0` and `0e999` be reported as the impostors they
     * are instead of being truncated into a canonical-looking leading `0`. When the token belongs to
     * a `resultCode` member, canonicity is judged FIRST: an impostor is recorded as the verdict even
     * if it is also not legal JSON, because "refused" is the right answer either way.
     */
    private function readNumberToken(bool $isResultCode): void
    {
        $start = $this->i;
        while ($this->i < $this->len) {
            $c = $this->raw[$this->i];
            if (($c >= '0' && $c <= '9') || $c === '-' || $c === '+' || $c === '.' || $c === 'e' || $c === 'E') {
                $this->i++;
                continue;
            }
            break;
        }
        if ($this->i === $start) {
            $this->fail();
        }
        $token = substr($this->raw, $start, $this->i - $start);

        if ($isResultCode && preg_match(self::CANONICAL_TOKEN_RE, $token) !== 1) {
            $this->bad = $token;

            return;
        }
        if (preg_match(self::JSON_NUMBER_RE, $token) !== 1) {
            $this->fail();
        }
    }

    private function literal(string $word): bool
    {
        if (substr($this->raw, $this->i, strlen($word)) === $word) {
            $this->i += strlen($word);

            return true;
        }

        return false;
    }

    private function peek(): ?string
    {
        return $this->i < $this->len ? $this->raw[$this->i] : null;
    }

    private function expect(string $c): void
    {
        if ($this->peek() !== $c) {
            $this->fail();
        }
        $this->i++;
    }

    private function skipWhitespace(): void
    {
        while ($this->i < $this->len) {
            $c = $this->raw[$this->i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $this->i++;
                continue;
            }

            return;
        }
    }

    private function fail(): void
    {
        throw new \RuntimeException('unreadable JSON');
    }
}
