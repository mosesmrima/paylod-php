<?php

declare(strict_types=1);

namespace Paylod\Support;

use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodInvalidRequestError;

/**
 * The money-path validators, in ONE place so every dispatch surface uses the SAME rules.
 *
 * They used to live as private statics on the client, which meant the simulator - a second public
 * dispatch surface - quietly had none of them: it accepted an idempotency key the production path
 * would have rejected, and turned a malformed 2xx into an empty payment id instead of a keyed
 * indeterminate error. A validator that only one caller uses is not a guard, it is a comment.
 */
final class Validate
{
    /** The payment states the API is allowed to report. Anything else is a malformed body. */
    public const PAYMENT_STATUSES = ['pending', 'success', 'failed', 'cancelled'];

    /**
     * The ONLY status an acknowledgement may carry, and the ONLY HTTP status a dispatched collect
     * answers with.
     *
     * `status` is a HARDCODED LITERAL "pending" on the backend, present on every 202 - including an
     * idempotent REPLAY, which returns the stored original ack rather than the current settled
     * state. So there is no legitimate ack carrying a settled status, and no legitimate ack missing
     * the field either: both are malformed, and requiring the literal cannot break replay.
     *
     * Note the asymmetry with a STATUS read, which legitimately carries terminal states. There,
     * `success` is not trusted from the string - it must be backed by a receipt or result code 0.
     */
    public const ACK_STATUS = 'pending';

    /**
     * POST /collect answers 202 Accepted and nothing else: the STK push has been handed to Daraja
     * and the payment is pending. Accepting ANY 2xx meant a bare 200 - the shape a cache, a proxy, a
     * captive portal, a stubbed endpoint or a rewritten route produces - was read as a successfully
     * dispatched charge. A 200 here is not a successful collect; it is a response from something
     * that is not the collect endpoint, and treating it as an ack invents a payment that may not
     * exist (or hides one that does).
     */
    public const ACK_HTTP_STATUS = 202;

    /**
     * Reject an idempotency key that would silently drop double-charge protection: blank/whitespace
     * keys, keys carrying control characters (which also cannot go in an HTTP header), and absurdly
     * long values. A caller-supplied key is the ONE thing standing between a double-click and a
     * double-charge, so a bad one must fail loudly rather than be quietly accepted.
     */
    public static function idempotencyKey(mixed $key): void
    {
        if (!is_string($key) || trim($key) === '') {
            throw new PaylodInvalidRequestError(
                'idempotencyKey must be a non-empty, non-whitespace string - a blank key silently drops '
                . 'double-charge protection.'
            );
        }
        // Bound the BYTE length: this goes out as an HTTP header value, and header sizes are bytes.
        if (strlen($key) > 255) {
            throw new PaylodInvalidRequestError('idempotencyKey must be 255 bytes or fewer.');
        }
        // The Unicode classes below are only meaningful over well-formed UTF-8. Invalid UTF-8 in a
        // header value is itself a bug, so reject it rather than fall back to a byte-wise check.
        if (preg_match('//u', $key) !== 1) {
            throw new PaylodInvalidRequestError('idempotencyKey must be valid UTF-8.');
        }
        // The FULL control ranges: C0 (U+0000-U+001F), DEL (U+007F) and C1 (U+0080-U+009F). All are
        // illegal in an HTTP header value, and a C1 byte pair sneaking through a byte-only C0 check
        // was how a mangled key used to be accepted silently.
        if (preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $key) === 1) {
            throw new PaylodInvalidRequestError(
                'idempotencyKey must not contain control characters (tabs, newlines, NULs, and the '
                . 'C1 range).'
            );
        }
        // Unicode-only whitespace: NBSP, ideographic space, the line/paragraph separators and the BOM
        // all survive trim() (which only strips ASCII), so "\u{00A0}" used to pass as a "real" key
        // while being invisible - and two visually identical keys would be two different charges.
        if (preg_match('/[^\P{Z}\x{0020}]|[\x{FEFF}\x{180E}]/u', $key) === 1) {
            throw new PaylodInvalidRequestError(
                'idempotencyKey must not contain Unicode-only whitespace (non-breaking space, '
                . 'ideographic space, BOM, line/paragraph separators). Use plain ASCII.'
            );
        }
        // Finally: PRINTABLE ASCII only (0x20-0x7E). HTTP header values are ASCII on the wire
        // (RFC 9110), so a printable non-ASCII character like the accented e in "ordr-cafe-1" is not
        // merely exotic - it is unrepresentable. It either blows up in the transport as an opaque
        // encoding error, or (worse, on a laxer stack) gets silently re-encoded, so two requests that
        // were meant to carry ONE key no longer do and the duplicate-charge guard quietly vanishes.
        if (preg_match('/[^\x20-\x7e]/', $key) === 1) {
            throw new PaylodInvalidRequestError(
                'idempotencyKey must be printable ASCII only (letters, digits and punctuation in the '
                . 'range 0x20-0x7E). HTTP header values are ASCII on the wire, so an accented or '
                . 'non-Latin character cannot be sent reliably - and a silently re-encoded key stops '
                . 'matching the retry it was meant to deduplicate. Derive the key from an id you '
                . 'control (a UUID, or your order id slugged to ASCII).'
            );
        }
    }

    /**
     * Validate the COMPLETE acknowledgement schema of a 2xx from a collect-shaped endpoint.
     *
     * Checking only `paymentId` was not enough. A 2xx with a blank `checkoutRequestId`, a missing
     * `status`, or a `status` of the wrong type is just as malformed - and just as INDETERMINATE:
     * the STK push may already be on a customer's phone. Every one of those must come back as a
     * keyed indeterminate error, never as a "successful" ack the caller will treat as a new payment
     * and retry under a fresh key.
     *
     * @param array<string,mixed> $parsed
     * @param ?callable(mixed):mixed $redact applied to the body before it is attached to the error
     */
    public static function collectAck(
        array $parsed,
        int $status,
        ?string $idempotencyKey = null,
        ?callable $redact = null,
    ): void {
        $problem = self::ackProblem($parsed, $status);
        if ($problem === null) {
            return;
        }

        throw new PaylodApiError(
            "paylod returned a {$status} whose body is not a valid acknowledgement ({$problem}) - the "
            . 'charge state is INDETERMINATE, the STK prompt may already be on the phone. Read the '
            . 'payment with this idempotencyKey before starting any new attempt; do NOT mint a fresh '
            . 'key (that risks a second charge).',
            $status,
            $redact === null ? $parsed : $redact($parsed),
            $idempotencyKey,
            true,
        );
    }

    /**
     * Validate the COMPLETE payment schema of a 2xx status body.
     *
     * This is a SHAPE check plus the binding check below. It deliberately says nothing about
     * whether the payment is paid: that question has exactly one home, {@see \Paylod\Semantics}.
     *
     * -- The binding check (law L1) ------------------------------------------------------------
     * This is the highest-value single check in the SDK. Nothing previously compared the `id` in the
     * response to the id in the request, so ANY mechanism that returned a DIFFERENT payment's record
     * - a cache keyed on the wrong thing, a proxy collapsing concurrent requests, an off-by-one in a
     * routing or authorization layer, a server-side bug, a deliberately crafted response - produced
     * a body the SDK validated happily and then classified on its own merits. If that other payment
     * happened to be settled and paid, the caller was told THEIR payment was paid, and shipped goods
     * for an order nobody had paid for.
     *
     * A response that answers a different question is not a MALFORMED response, it is a WRONG one,
     * and no amount of field-level shape checking can find it - every field is perfectly valid. The
     * request knows which payment it asked about; the answer has to say the same thing, or it is not
     * an answer at all. A mismatch is INDETERMINATE, because that is the honest reading: we now know
     * nothing about the payment we asked about, and "I do not know" must never collapse to "failed"
     * (reported as retryable, so the customer is charged twice) or to "paid".
     *
     * @param array<string,mixed> $parsed
     * @param ?callable(mixed):mixed $redact
     * @param ?string $expectedId the id that was REQUESTED. The body must agree with it.
     */
    public static function paymentBody(
        array $parsed,
        int $status,
        ?callable $redact = null,
        ?string $expectedId = null,
    ): void {
        $problem = self::paymentProblem($parsed, $expectedId);
        if ($problem === null) {
            return;
        }

        throw new PaylodApiError(
            "paylod returned a {$status} status body that is not a valid payment ({$problem}). The "
            . 'payment state could NOT be established from this response - treat it as unknown and '
            . 're-read it; do not record it as settled either way.',
            $status,
            $redact === null ? $parsed : $redact($parsed),
            null,
            true,
        );
    }

    /** @param array<string,mixed> $parsed */
    private static function ackProblem(array $parsed, int $httpStatus): ?string
    {
        // THE HTTP STATUS IS PART OF THE CONTRACT, and it is checked FIRST - before any field - so a
        // well-shaped body served by something that is not the collect endpoint cannot pass.
        if ($httpStatus !== self::ACK_HTTP_STATUS) {
            return "HTTP {$httpStatus}, expected " . self::ACK_HTTP_STATUS . ' Accepted - a collect '
                . 'that was genuinely dispatched always answers 202';
        }
        if (!self::isNonBlankString($parsed['paymentId'] ?? null)) {
            return 'no usable paymentId';
        }
        if (!self::isNonBlankString($parsed['checkoutRequestId'] ?? null)) {
            return 'no usable checkoutRequestId';
        }
        $status = $parsed['status'] ?? null;
        if ($status !== self::ACK_STATUS) {
            return 'status was ' . json_encode($status) . ', expected the literal "'
                . self::ACK_STATUS . '"';
        }

        return null;
    }

    /** @param array<string,mixed> $parsed */
    private static function paymentProblem(array $parsed, ?string $expectedId = null): ?string
    {
        if (!self::isNonBlankString($parsed['id'] ?? null)) {
            return 'no usable payment id';
        }

        // L1 BINDING. Checked before anything else about the record's CONTENTS, because if this
        // fails then every remaining field describes some OTHER payment, and reasoning about them is
        // not merely useless but actively misleading.
        if ($expectedId !== null && $parsed['id'] !== $expectedId) {
            return 'the body describes payment ' . json_encode($parsed['id']) . ' but '
                . json_encode($expectedId) . ' was requested - this response answers a different '
                . 'question, so it tells you NOTHING about the payment you asked about';
        }

        $status = $parsed['status'] ?? null;
        if (!is_string($status)) {
            return 'status is missing or not a string';
        }
        if (!in_array($status, self::PAYMENT_STATUSES, true)) {
            return "status \"{$status}\" is not a known payment state";
        }

        $receipt = $parsed['mpesaReceipt'] ?? null;
        if ($receipt !== null && !is_string($receipt)) {
            return 'mpesaReceipt is present but not a string';
        }
        $code = $parsed['resultCode'] ?? null;
        if ($code !== null && !is_int($code) && !is_string($code)) {
            return 'resultCode is present but is neither a number nor a string';
        }
        $desc = $parsed['resultDesc'] ?? null;
        if ($desc !== null && !is_string($desc)) {
            return 'resultDesc is present but not a string';
        }

        // NOTE: the "a success claim needs EVIDENCE" rule (law L2) deliberately does NOT live here
        // any more. It is a SEMANTIC rule, not a shape rule, and it belongs in exactly one place -
        // {@see \Paylod\Semantics::judge()} - so the status-read path, the webhook path and the
        // simulator cannot drift into disagreeing about what proves a payment. Enforcing it here as
        // well would also be actively wrong on the polling path: an evidence-free `success` is
        // INDETERMINATE, and an indeterminate payment must keep being polled so a webhook can settle
        // it, not abort wait() with a throw.

        return null;
    }

    private static function isNonBlankString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
