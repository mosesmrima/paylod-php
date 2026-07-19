<?php

declare(strict_types=1);

/**
 * NON-VACUITY HARNESS.
 *
 * A test that passes both WITH and WITHOUT the fix it claims to cover proves nothing. So every
 * protection landed in this release is verified the only way that actually settles the question:
 * REVERT the change in the source, run the test that is supposed to catch it, and require that it
 * FAILS. The file is restored afterwards, always, including on a fatal.
 *
 * Two traps this harness is built to avoid - both of them found the hard way:
 *
 *  1. A SELECTOR THAT MATCHES NOTHING. PHPUnit exits 0 and prints "No tests executed!" when a
 *     --filter matches no test. That reads exactly like "the mutation was not caught" - or, worse,
 *     like a pass - when in truth no test ever ran. So every selector is proven to match at least
 *     one test against CLEAN source before its verdict is trusted, and the count is reported.
 *  2. A NO-OP MUTATION. If the anchor text does not appear EXACTLY ONCE, the "mutation" changed
 *     nothing (or changed several things), and the resulting verdict is meaningless. Anchors are
 *     counted, and a case whose anchors do not match exactly once is reported BROKEN-ANCHOR rather
 *     than being silently counted as caught.
 *
 * Run: php scripts/non-vacuity.php
 * Exit code 0 only if every mutation was CAUGHT.
 */

chdir(dirname(__DIR__));

/**
 * Each case reverts ONE protection to its pre-release behaviour.
 *
 * `edits` is a list of [file, find, replace]. Several edits in one case exist for guarantees that
 * are enforced in more than one place: reverting a single implementation proves nothing, because
 * the other still holds the line - the mutation has to remove the GUARANTEE, not one of its copies.
 */
$CASES = [
    // == ROOT 1 - the transport owns the credential ==========================================
    [
        'id' => 'R1-gate',
        'what' => 'a custom HTTP client no longer requires the explicit opt-in',
        'test' => 'testRootOneACustomHttpClientIsAGatedTestOnlySeam',
        'edits' => [
            ['src/Paylod.php', "        if ((\$options['allowCustomHttpClient'] ?? false) !== true) {", '        if (false) {'],
        ],
    ],
    [
        'id' => 'R1-live',
        'what' => 'a custom HTTP client is permitted with a LIVE key (BOTH guards reverted)',
        'test' => 'testRootOneRefusesAnInjectedHttpClientWithALiveKey',
        'edits' => [
            ['src/Paylod.php', "        if (str_starts_with(\$apiKey, 'mp_live_')) {", '        if (false) {'],
            ['src/Http/Transport.php', '            if (str_starts_with(($apiKey)(), self::LIVE_PREFIX)) {', '            if (false) {'],
        ],
    ],
    [
        'id' => 'R1-live-tp',
        'what' => 'the TRANSPORT stops refusing a live key on its own terms (client gate left intact)',
        'test' => 'testRootOneTheTransportItselfRefusesALiveKeyWithACustomClient',
        'edits' => [
            ['src/Http/Transport.php', '            if (str_starts_with(($apiKey)(), self::LIVE_PREFIX)) {', '            if (false) {'],
        ],
    ],
    [
        'id' => 'R1-3xx',
        'what' => 'a 3xx response is no longer refused',
        'test' => 'testRootOneRefusesARedirectStatus',
        'edits' => [
            ['src/Http/Transport.php', '        if ($status >= 300 && $status < 400) {', '        if (false) {'],
        ],
    ],
    [
        'id' => 'R1-followed',
        'what' => 'a 2xx reached by FOLLOWING a redirect is accepted',
        'test' => 'testRootOneRefusesATwoHundredReachedByFollowingARedirect',
        'edits' => [
            ['src/Http/Transport.php', '        if (is_int($redirectCount) && $redirectCount > 0) {', '        if (false) {'],
        ],
    ],
    [
        'id' => 'R1-origin',
        'what' => 'the responding URL is not checked against the pinned origin',
        'test' => 'testRootOneRefusesAResponseFromOffThePinnedOrigin',
        'edits' => [
            [
                'src/Http/Transport.php',
                "        if (is_string(\$effective) && \$effective !== '') {\n            \$this->assertOnOrigin(\$effective, 'the responding URL');\n        }",
                '        unset($effective);',
            ],
        ],
    ],

    // == ROOT 2 - the semantic model ==========================================================
    [
        'id' => 'R2-bind',
        'what' => 'the returned payment id is not bound to the requested id (law L1)',
        'test' => 'testRootTwoIdBindingRejectsABodyDescribingADifferentPayment',
        'edits' => [
            ['src/Support/Validate.php', "        if (\$expectedId !== null && \$parsed['id'] !== \$expectedId) {", '        if (false) {'],
        ],
    ],
    [
        'id' => 'R2-202',
        'what' => 'any 2xx is accepted as a collect ack',
        'test' => 'testRootTwoACollectAckRequiresHttpTwoHundredAndTwo',
        'edits' => [
            ['src/Support/Validate.php', '        if ($httpStatus !== self::ACK_HTTP_STATUS) {', '        if (false) {'],
        ],
    ],
    [
        'id' => 'R2-ackstatus',
        'what' => 'a collect ack no longer requires the literal status "pending"',
        'test' => 'testRootTwoACollectAckRequiresTheLiteralPendingStatus',
        'edits' => [
            ['src/Support/Validate.php', '        if ($status !== self::ACK_STATUS) {', '        if (false) {'],
        ],
    ],
    [
        'id' => 'R2-pending0',
        'what' => 'a pending record carrying code 0 is treated as paid',
        'test' => 'testChangedAPendingRowCarryingCodeZeroIsNoLongerPaid',
        'edits' => [
            [
                'src/Semantics.php',
                "            'pending|success' => \$contradiction(\n                'status says pending while the evidence says the payment succeeded - a pending '\n                . 'record must never be reported as paid'\n            ),",
                "            'pending|success' => [self::VERDICT_PAID, 'REVERTED'],",
            ],
        ],
    ],
    [
        'id' => 'R2-receipt',
        'what' => 'a receipt beside a failure code no longer forces indeterminate (law L4)',
        'test' => 'testChangedAFailedRowCarryingAReceiptAndCode1032IsIndeterminateNotRetryable',
        'edits' => [
            [
                'src/Semantics.php',
                "        if (\$codeEvidence === self::EVIDENCE_SUCCESS || \$codeEvidence === self::EVIDENCE_NONE) {\n            return self::EVIDENCE_SUCCESS;\n        }",
                "        return \$codeEvidence === self::EVIDENCE_NONE ? self::EVIDENCE_SUCCESS : \$codeEvidence;",
            ],
        ],
    ],
    [
        'id' => 'R2-evidence',
        'what' => 'a bare status:success with no evidence is treated as paid (law L2)',
        'test' => 'testLawL2PaidAlwaysHasSuccessEvidence',
        'edits' => [
            [
                'src/Semantics.php',
                "            'success|none' => \$contradiction(\n                'status claims success but the record carries neither a receipt nor a result '\n                . 'code, so there is no evidence the payment actually settled'\n            ),",
                "            'success|none' => [self::VERDICT_PAID, 'REVERTED'],",
            ],
        ],
    ],
    [
        'id' => 'R2-render',
        'what' => 'PaymentOutcome renders an indeterminate verdict as a retryable failure again',
        'test' => 'testChangedAFailedRowCarryingAReceiptIsNeverRetryable',
        'edits' => [
            [
                'src/PaymentOutcome.php',
                '            Semantics::VERDICT_INDETERMINATE => new self(
                status: \'pending\',
                message: self::INDETERMINATE,
                retryable: false,',
                '            Semantics::VERDICT_INDETERMINATE => new self(
                status: \'failed\',
                message: self::INDETERMINATE,
                retryable: $detail[\'retryable\'] ?? true,',
            ],
        ],
    ],

    // == The PHP-specific findings ============================================================
    [
        'id' => 'B-varexport',
        'what' => 'the API key is stored back in a plain string property, where var_export() finds it',
        'test' => 'testVarExportNeverExposesTheApiKeyOrTheWebhookSecret',
        'edits' => [
            [
                'src/Paylod.php',
                "    private Closure \$apiKey;\n",
                "    private Closure \$apiKey;\n\n    private string \$apiKeyPlain;\n",
            ],
            [
                'src/Paylod.php',
                '        $this->apiKeyMasked = Redact::mask($apiKeyValue);',
                "        \$this->apiKeyPlain = \$apiKeyValue;\n        \$this->apiKeyMasked = Redact::mask(\$apiKeyValue);",
            ],
        ],
    ],
    [
        'id' => 'B-throwable',
        'what' => 'a non-Paylod throwable escapes collect() bare, with no key and no classification',
        'test' => 'testANonPaylodThrowableFromCollectIsWrappedWithTheKeyAndMarkedIndeterminate',
        'edits' => [
            [
                'src/Paylod.php',
                '            // A NON-PAYLOD throwable',
                "            throw \$e;\n            // A NON-PAYLOD throwable",
            ],
        ],
    ],

    // == FIFTH ROUND - the remaining money-correctness defects ================================
    [
        'id' => 'F-zero',
        'what' => 'success evidence is matched by loose numeric coercion again ("0e999", "+0", "00" become PAID)',
        'test' => 'testAFakeZeroCanNeverProduceAPaidVerdict',
        'edits' => [
            [
                'src/DarajaCatalog.php',
                "        if (\$raw === '0') {",
                "        if (\$raw !== '' && is_numeric(\$raw) && \$raw + 0 == 0) {",
            ],
            [
                'src/DarajaCatalog.php',
                '        return preg_match(self::CANONICAL_INTEGER_RE, $raw) === 1
            || preg_match(self::CANONICAL_DOTTED_RE, $raw) === 1;',
                '        return $raw !== \'\';',
            ],
        ],
    ],
    [
        'id' => 'F-zero-terminal',
        'what' => 'a non-canonical numeric code is treated as terminal again',
        'test' => 'testNonCanonicalNonZeroCodesAreNotTerminalEither',
        'edits' => [
            [
                'src/DarajaCatalog.php',
                "    private const CANONICAL_INTEGER_RE = '/^(?:0|[1-9][0-9]*)\\z/';",
                "    private const CANONICAL_INTEGER_RE = '/^[0-9eE+.\\-]+\\z/';",
            ],
        ],
    ],
    [
        'id' => 'F-table-default',
        'what' => 'the judge table maps failed/cancelled + in-flight evidence to in_flight rather than INDETERMINATE',
        'test' => 'testAContradictoryTerminalClaimIsIndeterminateNotInFlight',
        'edits' => [
            [
                'src/Semantics.php',
                "            'failed|in_flight' => \$contradiction(",
                "            'failed|in_flight' => [self::VERDICT_IN_FLIGHT, 'REVERTED'], 'failed|in_flight_dead' => \$contradiction(",
            ],
        ],
    ],
    [
        'id' => 'F-claim-closed',
        'what' => 'an unrecognised status is no longer normalised into the closed claim alphabet',
        'test' => 'testAnUnknownClaimIsIndeterminateAndNeverFallsThroughToAPermissiveDefault',
        'edits' => [
            [
                'src/Semantics.php',
                "            default => self::CLAIM_UNKNOWN,\n        };",
                "            default => self::CLAIM_SUCCESS,\n        };",
            ],
        ],
    ],
    [
        'id' => 'F-idem-required',
        'what' => 'collect() silently generates an idempotency key again instead of requiring one',
        'test' => 'testCollectRefusesToChargeWithoutACallerPersistedIdempotencyKey',
        'edits' => [
            [
                'src/Support/Validate.php',
                "        if ((\$params['unsafeGeneratedIdempotencyKey'] ?? false) !== true) {",
                '        if (false) {',
            ],
        ],
    ],
    [
        'id' => 'F-bind',
        'what' => 'a post-ack error keeps an unrelated pre-existing key / payment id (attach, not overwrite)',
        'test' => 'testAPostAckFailureCarriesTheAcknowledgedPaymentsOwnContextNotAStaleOne',
        'edits' => [
            [
                'src/Exceptions/PaylodException.php',
                "        \$this->idempotencyKey = \$idempotencyKey;\n        \$this->paymentId = \$paymentId !== '' ? \$paymentId : null;",
                "        \$this->attachIdempotencyKey(\$idempotencyKey);\n        \$this->attachPaymentId(\$paymentId);",
            ],
        ],
    ],
    [
        'id' => 'F-cause',
        'what' => 'the secret-bearing original throwable is chained as `previous` again',
        'test' => 'testTheSecretBearingOriginalIsNeverChainedAsThePreviousException',
        'edits' => [
            [
                'src/Paylod.php',
                "                \$idempotencyKey,\n                true,\n                self::sanitizedCause(\$e, \$this->redactor()),",
                "                \$idempotencyKey,\n                true,\n                \$e,",
            ],
        ],
    ],
    [
        'id' => 'F-cause-ack',
        'what' => 'the post-ack wrapper chains the secret-bearing original again',
        'test' => 'testThePostAckWrapperAlsoDropsTheSecretBearingOriginal',
        'edits' => [
            [
                'src/Paylod.php',
                "                \$ack['idempotencyKey'],\n                true,\n                self::sanitizedCause(\$e, \$this->redactor()),",
                "                \$ack['idempotencyKey'],\n                true,\n                \$e,",
            ],
        ],
    ],
    [
        'id' => 'F-tolerance',
        'what' => 'the anti-replay window loses its upper bound (PHP_INT_MAX accepts an ancient webhook)',
        'test' => 'testAnUnboundedToleranceIsRefusedRatherThanDisablingReplayProtection',
        'edits' => [
            [
                'src/Webhook.php',
                "        if (\$value > self::MAX_TOLERANCE_SEC) {\n            self::rejectTolerance(\$value);\n        }\n\n        \$seconds = self::requirePositiveInt(\$value, 'toleranceSec');\n        if (\$seconds > self::MAX_TOLERANCE_SEC) {\n            self::rejectTolerance(\$value);\n        }",
                "        \$seconds = self::requirePositiveInt(\$value, 'toleranceSec');",
            ],
        ],
    ],
    [
        'id' => 'F-status-redact',
        'what' => 'a successful status read returns its fields raw, so an echoed credential escapes',
        'test' => 'testAnEchoedCredentialCannotEscapeThroughASuccessfulStatusRead',
        'edits' => [
            [
                'src/Paylod.php',
                "            'resultDesc' => \$this->redact(\$p['resultDesc'] ?? null),",
                "            'resultDesc' => \$p['resultDesc'] ?? null,",
            ],
        ],
    ],
    [
        'id' => 'F-monotonic',
        'what' => 'wait deadlines go back to the wall clock, where an NTP step moves every deadline',
        'test' => 'testWaitDeadlinesUseAMonotonicClockNotTheWallClock',
        'edits' => [
            [
                'src/Paylod.php',
                '        return intdiv(hrtime(true), 1_000_000);',
                '        return (int) (microtime(true) * 1000);',
            ],
        ],
    ],
    [
        'id' => 'F-buffer',
        'what' => 'the response buffer loses its byte ceiling',
        'test' => 'testTheResponseBufferHasADocumentedByteCeiling',
        'edits' => [
            [
                'src/Http/CurlHttpClient.php',
                '        if ($bytes + $len > self::MAX_RESPONSE_BYTES) {',
                '        if (false) {',
            ],
        ],
    ],
    [
        'id' => 'F-laravel-cast',
        'what' => 'Laravel casts timeout_ms before validating it, so 1.5 silently becomes 1',
        'test' => 'testLaravelRefusesAFractionalTimeoutInsteadOfSilentlyTruncatingIt',
        'edits' => [
            [
                'src/Laravel/PaylodServiceProvider.php',
                "                'timeoutMs' => self::assertWholeNumber(\$config['timeout_ms'] ?? 30000, 'paylod.timeout_ms', 'PAYLOD_TIMEOUT_MS'),",
                "                'timeoutMs' => (int) (\$config['timeout_ms'] ?? 30000),",
            ],
        ],
    ],
    [
        'id' => 'F-simulator',
        'what' => 'the simulator drops a present-but-null field from the fingerprinted body again',
        'test' => 'testTheSimulatorNeitherDropsNorForwardsAnUnvalidatedField',
        'edits' => [
            [
                'src/Simulator.php',
                "        if (array_key_exists('description', \$params)) {\n            \$body['description'] = self::requireString(\$params['description'], 'description');\n        }",
                "        if (isset(\$params['description'])) {\n            \$body['description'] = \$params['description'];\n        }",
            ],
            [
                'src/Simulator.php',
                "        if (array_key_exists('accountReference', \$params)) {\n            // The backend calls this field `accountRef`; the SDK calls it `accountReference`.\n            \$body['accountRef'] = self::requireString(\$params['accountReference'], 'accountReference');\n        }",
                "        if (isset(\$params['accountReference'])) {\n            \$body['accountRef'] = \$params['accountReference'];\n        }",
            ],
            [
                'src/Simulator.php',
                "            if (!is_array(\$metadata)) {",
                '            if (false) {',
            ],
        ],
    ],

    // == The webhook, through the same model ==================================================
    [
        'id' => 'W-evidence',
        'what' => 'webhook success evidence is not required',
        'test' => 'testWebhookRejectsASignedPaymentSuccessWithNoEvidence',
        'edits' => [
            ['src/Webhook.php', "        if (\$type === 'payment.success' && \$judgement->verdict !== Semantics::VERDICT_PAID) {", '        if (false) {'],
        ],
    ],
    [
        'id' => 'W-consistency',
        'what' => 'webhook type/status consistency is not checked',
        'test' => 'testWebhookRejectsASignedSuccessWhoseDataStatusContradictsTheType',
        'edits' => [
            ['src/Webhook.php', "        if (\$d['status'] !== \$expectedStatus) {", '        if (false) {'],
        ],
    ],
    [
        'id' => 'W-failed',
        'what' => 'a failure notice carrying a receipt is delivered as a settled failure',
        'test' => 'testWebhookRejectsAFailureNoticeCarryingAReceipt',
        'edits' => [
            ['src/Webhook.php', "        if (\$type === 'payment.failed' && \$judgement->verdict !== Semantics::VERDICT_FAILED) {", '        if (false) {'],
        ],
    ],


    // == SIXTH ROUND - validate before normalising, and re-derive every conclusion ==============
    //
    // NOTE ON ANCHORS: every `find` below is a SINGLE-QUOTED PHP string. A double-quoted anchor
    // containing $resultCode / $raw / $status is interpolated by PHP before it is ever compared,
    // so it silently matches 0x and the case reports BROKEN-ANCHOR.
    [
        'id' => 'S6-lexeme-trim',
        'what' => 'the result code is trimmed before validation again, laundering " 1032" into the retryable 1032 entry',
        'test' => 'testAPaddedTerminalCodeIsNeverDecodedAsTheRetryableEntry',
        'edits' => [
            [
                'src/DarajaCatalog.php',
                '        if (is_string($resultCode)) {' . "\n" . '            return $resultCode;' . "\n" . '        }',
                '        if (is_string($resultCode)) {' . "\n" . '            return trim($resultCode);' . "\n" . '        }',
            ],
        ],
    ],
    [
        'id' => 'S6-lexeme-float',
        'what' => 'a float result code is stringified again, so 0.0 and " 0 " become SUCCESS',
        'test' => 'testNoOtherZeroLikeRepresentationIsEverSuccess',
        'edits' => [
            [
                'src/DarajaCatalog.php',
                '        return \'\';' . "\n" . '    }' . "\n" . "\n" . '    /**' . "\n" . '     * THE EXACT overloaded Daraja business code.',
                '        return $resultCode === null ? \'\' : trim((string) $resultCode);' . "\n" . '    }' . "\n" . "\n" . '    /**' . "\n" . '     * THE EXACT overloaded Daraja business code.',
            ],
        ],
    ],
    [
        'id' => 'S6-anchor',
        'what' => 'the canonical-code regex is anchored with $ again, so "1032\n" passes as canonical',
        'test' => 'testNonCanonicalNonZeroCodesAreNotTerminalEither',
        'edits' => [
            [
                'src/DarajaCatalog.php',
                "    private const CANONICAL_INTEGER_RE = '/^(?:0|[1-9][0-9]*)\\z/';",
                "    private const CANONICAL_INTEGER_RE = '/^(?:0|[1-9][0-9]*)$/';",
            ],
        ],
    ],
    [
        'id' => 'S6-rawjson-webhook',
        'what' => 'the webhook decodes without checking raw numeric lexemes, so `-0` is laundered into a paid success',
        'test' => 'testARawZeroLexemeNeverPassesWebhookVerification',
        'edits' => [
            [
                'src/Webhook.php',
                '        $badToken = JsonLexeme::nonCanonicalResultCodeToken($raw);',
                '        $badToken = null;',
            ],
        ],
    ],
    [
        'id' => 'S6-rawjson-money',
        'what' => 'the status path decodes without checking raw numeric lexemes',
        'test' => 'testARawZeroLexemeOnTheStatusPathIsRefusedAsIndeterminate',
        'edits' => [
            [
                'src/Paylod.php',
                '                $badToken = JsonLexeme::nonCanonicalResultCodeToken($text);',
                '                $badToken = null;',
            ],
        ],
    ],
    [
        'id' => 'S6-derived',
        'what' => 'the webhook forwards server-supplied conclusions, so a forged retryable=true reaches the handler',
        'test' => 'testAForgedRetryableOnATerminalFailureIsOverwritten',
        'edits' => [
            [
                'src/Webhook.php',
                '        return self::withAuthoritativeDerivedFields($event);',
                '        return $event;',
            ],
        ],
    ],
    [
        'id' => 'S6-decoded-synth',
        'what' => 'a missing decoded block is left absent rather than synthesized',
        'test' => 'testAMissingDecodedBlockIsSynthesizedAndNonRetryable',
        'edits' => [
            [
                'src/Webhook.php',
                '        $decoded = $outcome->detail ?? self::synthesizeDecoded($outcome);',
                '        $decoded = $outcome->detail ?? [\'category\' => \'unknown\', \'retryable\' => true];',
            ],
        ],
    ],
    [
        'id' => 'S6-strip-unknown',
        'what' => 'derived fields on an unknown event type are forwarded unchecked',
        'test' => 'testDerivedFieldsAreStrippedFromUnknownEventTypes',
        'edits' => [
            [
                'src/Webhook.php',
                "            \$out['data'] = self::scalarsOnly(\$data);\n\n            return \$out;",
                '            return $event;',
            ],
        ],
    ],
    [
        'id' => 'S6-terminal-redirect',
        'what' => 'a detected credential compromise is a connection error again, so the retry loop re-dispatches the charge',
        'test' => 'testRootOneARedirectOnCollectDispatchesTheChargeExactlyOnce',
        'edits' => [
            [
                'src/Http/Transport.php',
                '            throw new PaylodCredentialCompromiseError($this->scrub(' . "\n" . '                "paylod returned an unexpected redirect',
                '            throw new \Paylod\Exceptions\PaylodConnectionError($this->scrub(' . "\n" . '                "paylod returned an unexpected redirect',
            ],
        ],
    ],
    [
        'id' => 'S6-id-grammar',
        'what' => 'acknowledgement identifiers lose their shape check',
        'test' => 'testAnAckWithAMalformedIdentifierIsRefused',
        'edits' => [
            [
                'src/Support/Validate.php',
                '        if (preg_match(self::IDENTIFIER_RE, $value) !== 1) {',
                '        if (false) {',
            ],
        ],
    ],
    [
        'id' => 'S6-id-credential',
        'what' => 'an identifier carrying the bearer token is accepted, putting the key in the payment log',
        'test' => 'testAnAckWhoseIdentifiersCarryTheApiKeyIsRefusedAsIndeterminate',
        'edits' => [
            [
                'src/Support/Validate.php',
                '        if ($redact !== null && $redact($value) !== $value) {',
                '        if (false) {',
            ],
        ],
    ],
    [
        'id' => 'S6-baseurl-redact',
        'what' => 'baseUrl validation errors quote the credential they just caught',
        'test' => 'testBaseUrlValidationErrorsDoNotQuoteTheCredential',
        'edits' => [
            [
                'src/Http/Transport.php',
                '        $shown = Redact::text($baseUrl, [$apiKey]);',
                '        $shown = $baseUrl;',
            ],
        ],
    ],
    [
        'id' => 'S6-header-bytes',
        'what' => 'response headers lose their aggregate byte ceiling',
        'test' => 'testTheResponseHeadersHaveAnAggregateCeiling',
        'edits' => [
            [
                'src/Http/CurlHttpClient.php',
                '        if ($headerBytes > self::MAX_HEADER_BYTES) {',
                '        if (false) {',
            ],
        ],
    ],
    [
        'id' => 'S6-header-count',
        'what' => 'response headers lose their count ceiling, so many tiny headers still exhaust memory',
        'test' => 'testTheResponseHeadersHaveAnAggregateCeiling',
        'edits' => [
            [
                'src/Http/CurlHttpClient.php',
                '        if (!isset($headers[$key]) && count($headers) >= self::MAX_HEADER_COUNT) {',
                '        if (false) {',
            ],
        ],
    ],
    [
        'id' => 'S6-config-cast',
        'what' => 'the shipped config file pre-casts timeout_ms, silencing the provider check one layer up',
        'test' => 'testAFractionalEnvValueSurvivesTheConfigFileAndIsRefused',
        'edits' => [
            [
                'config/paylod.php',
                "    'timeout_ms' => env('PAYLOD_TIMEOUT_MS', 30000),",
                "    'timeout_ms' => (int) env('PAYLOD_TIMEOUT_MS', 30000),",
            ],
        ],
    ],
    [
        'id' => 'S6-trace-args',
        'what' => 'the collect validator closure stops being #[\SensitiveParameter], leaking a reflected key into the trace',
        'test' => 'testSecretsAreScrubbedFromStackTracesWithExceptionArgsEnabled',
        'edits' => [
            [
                'src/Paylod.php',
                'function (#[\SensitiveParameter] array $parsed, int $status) use ($idempotencyKey, &$acknowledgedPaymentId): void {',
                'function (array $parsed, int $status) use ($idempotencyKey, &$acknowledgedPaymentId): void {',
            ],
        ],
    ],
    // == ROUND 8 - the escaped-key result-code bypass, and the round-7 gaps that fed it ======
    [
        'id' => 'r8-escaped-result-code-key',
        'what' => 'the raw result-code guard goes back to matching only the LITERAL bytes "resultCode"',
        'test' => 'testEveryEscapedSpellingOfResultCodeIsScanned',
        'edits' => [
            ['src/Support/JsonLexeme.php', '            $isTarget = $name === self::TARGET_KEY;', '            $isTarget = substr($this->raw, $nameStart, $this->i - $nameStart) === \'"\' . self::TARGET_KEY . \'"\';'],
        ],
    ],
    [
        'id' => 'r8-status-path-escaped-key',
        'what' => 'the STATUS path stops seeing an escaped-key impostor zero (same revert)',
        'test' => 'testTheStatusPathRefusesAnEscapedKeyImpostorZero',
        'edits' => [
            ['src/Support/JsonLexeme.php', '            $isTarget = $name === self::TARGET_KEY;', '            $isTarget = substr($this->raw, $nameStart, $this->i - $nameStart) === \'"\' . self::TARGET_KEY . \'"\';'],
        ],
    ],
    [
        'id' => 'r8-webhook-path-escaped-key',
        'what' => 'the SIGNED WEBHOOK path stops seeing an escaped-key impostor zero (same revert)',
        'test' => 'testTheSignedWebhookPathRefusesAnEscapedKeyImpostorZero',
        'edits' => [
            ['src/Support/JsonLexeme.php', '            $isTarget = $name === self::TARGET_KEY;', '            $isTarget = substr($this->raw, $nameStart, $this->i - $nameStart) === \'"\' . self::TARGET_KEY . \'"\';'],
        ],
    ],
    [
        'id' => 'r8-escaped-result-code-key-controls',
        'what' => 'the guard refuses EVERY numeric result code, canonical ones included',
        'test' => 'testLegitimateBodiesAreNotRefused',
        'edits' => [
            ['src/Support/JsonLexeme.php', '        if ($isResultCode && preg_match(self::CANONICAL_TOKEN_RE, $token) !== 1) {', '        if ($isResultCode) {'],
        ],
    ],
    [
        'id' => 'r8-webhook-data-allowlist',
        'what' => 'verified event `data` is forwarded verbatim instead of rebuilt from the allowlist',
        'test' => 'testNestedRetryabilityClaimsDoNotSurviveVerification',
        'edits' => [
            ['src/Webhook.php', '        $out[\'data\'] = self::pickTyped($data, self::PAYMENT_DATA_KEYS, self::PAYMENT_DATA_TYPES, \'data\') + [', '        $out[\'data\'] = $data + ['],
        ],
    ],
    [
        'id' => 'r8-webhook-root-allowlist',
        'what' => 'the verified event ROOT is forwarded verbatim instead of rebuilt from the allowlist',
        'test' => 'testArbitraryRootAndDataFieldsAreDropped',
        'edits' => [
            ['src/Webhook.php', '        $out = self::pickTyped($event, self::ROOT_KEYS, self::ROOT_TYPES, \'event\');', '        $out = $event;'],
        ],
    ],
    [
        'id' => 'r8-webhook-unknown-type',
        'what' => 'an UNKNOWN event type forwards its whole nested data payload again',
        'test' => 'testAnUnknownEventTypeIsRepresentedMinimally',
        'edits' => [
            ['src/Webhook.php', '            $out[\'data\'] = self::scalarsOnly($data);', '            $out[\'data\'] = $data;'],
        ],
    ],
    [
        'id' => 'r8-diagnostic-redaction',
        'what' => 'malformed-2xx diagnostics stop going through the redactor (BOTH surfaces reverted)',
        'test' => 'testMalformedBodyDiagnosticsAreRedacted',
        'edits' => [
            ['src/Support/Validate.php', '        // THE FINISHED DIAGNOSTIC, THROUGH THE REDACTOR. Every branch above quotes some part of a
        // SERVER-CONTROLLED body back at the reader - `status` via json_encode, a mismatched id, a
        // status string. Redacting each site individually is a list nobody will keep complete, and
        // one missed branch puts an echoed bearer token or webhook secret into an exception message
        // and from there into the application\'s error log. So the whole string is scrubbed once,
        // here, where no future branch can be added downstream of it.
        $problem = $redact === null ? $problem : (string) $redact($problem);', '        // reverted'],
            ['src/Support/Validate.php', '        // The same single scrub the acknowledgement path applies - see collectAck().
        $problem = $redact === null ? $problem : (string) $redact($problem);', '        // reverted'],
        ],
    ],
    [
        'id' => 'r8-webhook-stringable-refused',
        'what' => 'a Stringable webhook body is materialised in full before its size is checked',
        'test' => 'testANonStringPayloadIsRefusedRatherThanMaterialised',
        'edits' => [
            ['src/Webhook.php', '        if (!is_string($payload)) {', '        if (false) {'],
            ['src/Webhook.php', '
        $raw = $payload;
', '
        $raw = (string) $payload;
'],
        ],
    ],
    [
        'id' => 'r8-signature-header-bounds',
        'what' => 'the signature header is exploded with no byte or segment ceiling (BOTH reverted)',
        'test' => 'testTheSignatureHeaderIsBoundedBeforeItIsParsed',
        'edits' => [
            ['src/Webhook.php', '        if ($sigBytes > self::MAX_SIGNATURE_HEADER_BYTES) {', '        if (false) {'],
            ['src/Webhook.php', '        if (substr_count($signature, \',\') + 1 > self::MAX_SIGNATURE_HEADER_SEGMENTS) {', '        if (false) {'],
        ],
    ],
    [
        'id' => 'r8-sanitized-cause-trace',
        'what' => 'the sanitized surrogate keeps the original throwable in its OWN trace arguments',
        'test' => 'testTheSanitizedSurrogateDoesNotCarryTheOriginalInItsOwnTraceArguments',
        'edits' => [
            ['src/Paylod.php', '        #[\\SensitiveParameter] \\Throwable $e,
        #[\\SensitiveParameter] \\Closure $redact,', '        \\Throwable $e,
        \\Closure $redact,'],
        ],
    ],
    [
        'id' => 'r8-phone-anchor',
        'what' => 'the phone grammar goes back to `$`, which accepts a trailing newline',
        'test' => 'testAPhoneWithATrailingNewlineIsNotValid',
        'edits' => [
            ['src/Phone.php', '    public const INPUT_RE = \'/^(?:\\+?254|0)?[17]\\d{8}\\z/\';', '    public const INPUT_RE = \'/^(?:\\+?254|0)?[17]\\d{8}$/\';'],
        ],
    ],
    [
        'id' => 'r8-simulator-key-required',
        'what' => 'the simulator silently GENERATES an idempotency key again',
        'test' => 'testTheSimulatorRequiresAnIdempotencyKeyJustLikeProduction',
        'edits' => [
            ['src/Simulator.php', '        $idempotencyKey = Validate::collectIdempotencyKey($params, \'simulate.collect\', static function (): void {', '        $idempotencyKey = $params[\'idempotencyKey\'] ?? \\Paylod\\Support\\Uuid::v4();
        $unusedWarner = (static function (): void {'],
        ],
    ],
    [
        'id' => 'r8-simulator-amount-ceiling',
        'what' => 'the simulator goes back to its own weaker "any positive int" amount rule',
        'test' => 'testTheSimulatorEnforcesTheProductionAmountCeiling',
        'edits' => [
            ['src/Simulator.php', '        $amount = Validate::collectAmount($params[\'amount\'] ?? 1, \'simulate.collect\');', '        $amount = (int) ($params[\'amount\'] ?? 1);'],
        ],
    ],
    [
        'id' => 'r8-simulator-failure-context',
        'what' => 'a simulator dispatch failure loses the effective idempotency key again',
        'test' => 'testASimulatorDispatchFailureCarriesTheEffectiveKey',
        'edits' => [
            ['src/Simulator.php', '                $e->attachIdempotencyKey($idempotencyKey);', '                // reverted'],
        ],
    ],
    [
        'id' => 'r8-simulator-outcomes-allowlist',
        'what' => 'the acknowledged `outcomes` list is forwarded unvalidated and unredacted again',
        'test' => 'testTheAcknowledgedOutcomesAreRebuiltFromTheClosedSet',
        'edits' => [
            ['src/Simulator.php', '            \'outcomes\' => self::allowlistedOutcomes($ack[\'outcomes\'] ?? []),', '            \'outcomes\' => $ack[\'outcomes\'] ?? [],'],
        ],
    ],
    [
        'id' => 'r8-simulator-validate-before-mutate',
        'what' => 'pay() validates its outcome only AFTER collect() has created a payment',
        'test' => 'testPayValidatesTheOutcomeBeforeCreatingAnything',
        'edits' => [
            ['src/Simulator.php', '        $outcome = $params[\'outcome\'] ?? null;
        self::assertKnownOutcome($outcome);', '        $outcome = $params[\'outcome\'] ?? null;'],
        ],
    ],
    [
        'id' => 'r8-retryable-cross-product',
        'what' => 'the nested detail keeps the raw catalog retryable on an INDETERMINATE record, '
            . 'so a handler reading the decoded block re-charges a customer holding a receipt',
        'test' => 'testEveryExposedRetryableFieldAgreesAcrossTheCrossProduct',
        'edits' => [
            ['src/PaymentOutcome.php', '            Semantics::VERDICT_INDETERMINATE => new self(
                status: \'pending\',
                message: self::INDETERMINATE,
                retryable: false,
                paid: false,
                paymentId: $paymentId,
                receipt: null,
                code: $code,
                detail: self::nonRetryableDetail($detail),', '            Semantics::VERDICT_INDETERMINATE => new self(
                status: \'pending\',
                message: self::INDETERMINATE,
                retryable: false,
                paid: false,
                paymentId: $paymentId,
                receipt: null,
                code: $code,
                detail: $detail,'],
        ],
    ],
    [
        'id' => 'r8-no-decompression-before-cap',
        'what' => 'the transport asks cURL for automatic decompression, so the byte ceiling is '
            . 'applied to the EXPANDED body instead of the wire bytes',
        'test' => 'testTheTransportNeverAsksForAutomaticDecompression',
        'edits' => [
            ['src/Http/CurlHttpClient.php', '            CURLOPT_RETURNTRANSFER => true,', '            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => \'\','],
        ],
    ],
    // == ROUND 9 - the composed money bug ======================================================
    [
        'id' => 'r9-receipt-grammar',
        'what' => 'receipt evidence goes back to a non-emptiness test, so the redactor\'s own '
            . '`[redacted]` output passes as proof of payment',
        'test' => 'testTheReceiptGrammarAcceptsOnlyReceiptShapedValues',
        'edits' => [
            ["src/Semantics.php", "    public const RECEIPT_RE = '/^[A-Z0-9]{10}\\z/';", "    public const RECEIPT_RE = '/^.+\\z/';"],
        ],
    ],
    [
        'id' => 'r9-receipt-grammar-paid',
        'what' => 'the SAME revert, proven on the PAID path: a redaction marker becomes proof of payment',
        'test' => 'testARedactedCredentialIsNotProofOfPayment',
        'edits' => [
            ['src/Semantics.php', '        return is_string($receipt) && self::isReceipt($receipt);', '        return is_string($receipt) && trim($receipt) !== \'\';'],
        ],
    ],
    [
        'id' => 'r9-receipt-grammar-webhook',
        'what' => 'the SAME revert, proven on the WEBHOOK path',
        'test' => 'testARedactedCredentialIsNotProofOfPaymentOnTheWebhookPath',
        'edits' => [
            ['src/Semantics.php', '        return is_string($receipt) && self::isReceipt($receipt);', '        return is_string($receipt) && trim($receipt) !== \'\';'],
        ],
    ],
    [
        'id' => 'r9-marker-audit',
        'what' => 'the redaction marker satisfies a correlation check again (the idempotency key)',
        'test' => 'testTheRedactionMarkerSatisfiesNoEvidenceIdentifierOrCorrelationCheck',
        'edits' => [
            ['src/Support/Validate.php', '        if (Redact::containsPlaceholder($key)) {', '        if (false) {'],
        ],
    ],
    [
        'id' => 'r9-webhook-refuses-echoed-secret',
        'what' => 'a signed body echoing the webhook secret is sanitised and delivered instead of refused',
        'test' => 'testASignedBodyEchoingTheWebhookSecretIsRefusedNotSanitised',
        'edits' => [
            ['src/Webhook.php', '        if (Redact::contains($raw, [$secret])) {', '        if (false) {'],
        ],
    ],
    [
        'id' => 'r9-depth-invariant',
        'what' => 'the redactor stops shallower than the parser again, so a secret nested past its '
            . 'ceiling is parsed in and then walked past',
        'test' => 'testTheRedactionDepthIsPinnedToTheParseDepth',
        'edits' => [
            ['src/Support/Redact.php', '    public const MAX_DEPTH = JsonLexeme::MAX_DEPTH;', '    public const MAX_DEPTH = 12;'],
        ],
    ],
    [
        'id' => 'r9-500-exact-code',
        'what' => 'the terminal 500.* description branch matches a PREFIX again and runs BEFORE the '
            . 'code\'s form is validated',
        'test' => 'testAMalformed500CodeIsNotPromotedToTerminalByItsDescription',
        'edits' => [
            ['src/DarajaCatalog.php', '        if (!self::isCanonicalCode($raw)) {
            return \'pending\';
        }', '        if (str_starts_with($raw, \'500.\') && preg_match(self::TERMINAL_500_MESSAGE_RE, $desc) === 1) {
            return \'failed\';
        }
        if (!self::isCanonicalCode($raw)) {
            return \'pending\';
        }'],
        ],
    ],
    [
        'id' => 'r9-unknown-code-indeterminate',
        'what' => 'any canonically shaped positive integer is terminal failure again, catalogued or not',
        'test' => 'testAnUncataloguedCanonicalCodeIsIndeterminateEverywhere',
        'edits' => [
            ['src/DarajaCatalog.php', '        if (isset(self::terminalStkCodes()[$raw])) {
            return \'failed\';
        }', '        if (preg_match(\'/^[1-9][0-9]*\z/\', $raw) === 1) {
            return \'failed\';
        }'],
        ],
    ],
    [
        'id' => 'r9-allowlist-types',
        'what' => 'allowlisted webhook fields are copied without checking their value TYPES, so a '
            . 'payload-supplied retry conclusion rides through inside an allowlisted name',
        'test' => 'testAStructuredValueInAScalarAllowlistedFieldIsRefused',
        'edits' => [
            ['src/Webhook.php', '            if ($value !== null && !self::isOfType($value, $types[$key])) {', '            if (false) {'],
        ],
    ],
    [
        'id' => 'r9-collect-binds-payment-id',
        'what' => 'a malformed 202 carrying a usable paymentId attaches only the idempotency key again',
        'test' => 'testAMalformedAcknowledgementStillBindsAUsablePaymentId',
        'edits' => [
            ['src/Paylod.php', '                if ($acknowledgedPaymentId !== null) {
                    $e->bindToAcknowledgedPayment($idempotencyKey, $acknowledgedPaymentId);
                } else {
                    $e->attachIdempotencyKey($idempotencyKey);
                }', '                $e->attachIdempotencyKey($idempotencyKey);'],
        ],
    ],
    [
        'id' => 'r9-decode-error-redacts',
        'what' => 'decodeError() stops applying the client redactor AND the catalog stops '
            . 'shape-scrubbing its fallback description (BOTH layers reverted)',
        'test' => 'testDecodeErrorRedactsTheClientsOwnCredentials',
        'edits' => [
            ['src/Paylod.php', '        $decoded = $this->redact(DarajaCatalog::decode($resultCode, $rawDesc));', '        $decoded = DarajaCatalog::decode($resultCode, $rawDesc);'],
            ['src/DarajaCatalog.php', '        $desc = trim(Redact::text($rawDesc ?? \'\', []));', '        $desc = trim($rawDesc ?? \'\');'],
        ],
    ],
    [
        'id' => 'r9-scanner-divergence',
        'what' => 'the scanner/parser divergence cross-check is removed, so a body the guard cannot '
            . 'read but json_decode() CAN is waved through',
        'test' => 'testABodyTheScannerCannotReadButPhpCanIsRefused',
        'edits' => [
            ['src/Support/JsonLexeme.php', '            return json_last_error() === JSON_ERROR_NONE ? self::UNREADABLE : null;', '            return null;'],
        ],
    ],
    [
        'id' => 'r9-adversarial-sweep-code-field',
        'what' => 'the offline decoder copies an unrecognised raw ResultCode lexeme into its public '
            . '`code` field again - ONE OF THE TWO LEAKS THE SWEEP ITSELF FOUND',
        'test' => 'testNoPublicObjectOrExceptionEverCarriesACredential',
        'edits' => [
            ['src/DarajaCatalog.php', '        if ($code === \'\' || strlen($code) > 32 || !self::isCanonicalCode($code)) {', '        if (false) {'],
        ],
    ],
    [
        'id' => 'r9-adversarial-sweep-claimed',
        'what' => 'Judgement::$claimed goes back to a verbatim copy of the server\'s status string - '
            . 'THE SECOND LEAK THE SWEEP FOUND',
        'test' => 'testNoPublicObjectOrExceptionEverCarriesACredential',
        'edits' => [
            ['src/Semantics.php', '        $claimed = is_string($rawClaim) ? Redact::text($rawClaim, []) : \'\';', '        $claimed = is_string($rawClaim) ? $rawClaim : \'\';'],
        ],
    ],
];

/**
 * Run PHPUnit for one selector.
 *
 * @return array{code:int, passed:int, failed:int, executed:bool}
 */
function runTests(string $selector): array
{
    // The selector matches the method name at a boundary, NOT at end-of-string: a @dataProvider
    // test is named `method with data set "..."`, so a `$` anchor matched none of them and
    // reported BROKEN-SELECTOR - this harness's own documented trap, sprung on itself.
    $cmd = 'vendor/bin/phpunit --filter ' . escapeshellarg('/::' . preg_quote($selector, '/') . '(?:$| with data set)/')
        . ' --do-not-cache-result 2>&1';
    exec($cmd, $lines, $code);
    $out = implode("\n", $lines);

    $passed = 0;
    // "OK (12 tests, 30 assertions)" on a clean run.
    if (preg_match('/OK \((\d+) test/', $out, $m) === 1) {
        $passed = (int) $m[1];
    }
    // "Tests: 12, Assertions: 30, Failures: 1, Errors: 2." on anything else. EVERY label is read,
    // not just the first - see $counts below for why that matters.
    if (preg_match('/Tests: (\d+),/', $out, $m) === 1) {
        $passed = max($passed, (int) $m[1]);
    }

    // THE SECOND TRAP THIS HARNESS EXISTS TO AVOID, and the one it was itself sprung on:
    // A NONZERO EXIT IS NOT A CAUGHT MUTATION.
    //
    // The verdict used to be `$code !== 0`. PHPUnit exits nonzero for a FAILURE, but also for an
    // ERROR, a WARNING, a RISKY test, an INCOMPLETE one, or a fatal in the mutated source. Those
    // are all "the mutation broke something", not "the test noticed the protection was gone" - and
    // a mutation that makes the suite crash would have been reported as a protection working
    // perfectly. So the counts are read individually and a catch requires a genuine ASSERTION
    // FAILURE with nothing else wrong.
    $counts = [];
    foreach (['Failures', 'Errors', 'Warnings', 'Risky', 'Skipped', 'Incomplete'] as $label) {
        $counts[$label] = preg_match('/\b' . $label . ': (\d+)/', $out, $m) === 1 ? (int) $m[1] : 0;
    }

    // THE FIRST TRAP: PHPUnit exits 0 and says this when the filter matched nothing.
    $executed = !str_contains($out, 'No tests executed!') && $passed > 0;

    return [
        'code' => $code,
        'passed' => $passed,
        'failed' => $counts['Failures'],
        'counts' => $counts,
        'executed' => $executed,
        'out' => $out,
    ];
}

/**
 * A run is CLEAN only if nothing at all went wrong - no failure, and no error/warning/risky/skipped
 * /incomplete either. Applied to the pre-mutation run so a selector that is already noisy cannot
 * have its noise mistaken for a catch afterwards.
 *
 * @param array{code:int,counts:array<string,int>} $run
 */
function isSpotless(array $run): bool
{
    return $run['code'] === 0 && array_sum($run['counts']) === 0;
}

/**
 * A mutation is CAUGHT only by a real assertion failure, with nothing else wrong.
 *
 * @param array{counts:array<string,int>} $run
 */
function isGenuineCatch(array $run): bool
{
    $c = $run['counts'];

    // WARNINGS are the one category deliberately tolerated, and only alongside a real failure.
    // Some protections warn ON PURPOSE when they are removed - reverting the required-idempotency-key
    // guard makes the unsafe path run, and that path emits E_USER_WARNING by design. The warning is
    // therefore EVIDENCE the mutation took effect, not noise, and the assertion failure beside it is
    // the verdict. The clean run is required to be spotless (see isSpotless), so any warning here
    // was caused by the mutation.
    //
    // ERRORS, RISKY, SKIPPED and INCOMPLETE are never tolerated: each is a state in which the test
    // did not actually reach and evaluate its assertions, which is precisely what a crashed mutation
    // run looks like.
    return $c['Failures'] >= 1
        && $c['Errors'] === 0
        && $c['Risky'] === 0
        && $c['Skipped'] === 0
        && $c['Incomplete'] === 0;
}

/**
 * Every `nv:<id>` tag written in a test docblock must correspond to a registered case.
 *
 * A protection with a test but no mutation case is a protection nobody has proven is load-bearing,
 * and the tag is the author saying they intended to prove it. Left unchecked the list drifts: the
 * round-7 protections all had tests, none had cases, and the harness reported 48/48 caught.
 *
 * @param list<array{id:string}> $cases
 * @return list<string> the tags that are not registered
 */
function unregisteredTags(array $cases): array
{
    $registered = array_column($cases, 'id');
    $tags = [];
    foreach (glob('tests/*.php') ?: [] as $file) {
        if (preg_match_all('/nv:([A-Za-z0-9\-]+)/', (string) file_get_contents($file), $m) >= 1) {
            foreach ($m[1] as $tag) {
                $tags[$tag] = true;
            }
        }
    }

    return array_values(array_diff(array_keys($tags), $registered));
}

$results = [];
/** @var array<string,string> $restore file => original contents, for the shutdown guard */
$restore = [];

// Restore every touched file even on a fatal error or a Ctrl-C, so a crashed harness can never
// leave a deliberately broken protection sitting in the working tree.
register_shutdown_function(static function () use (&$restore): void {
    foreach ($restore as $file => $original) {
        file_put_contents($file, $original);
    }
});

foreach ($CASES as $c) {
    // 1. PROVE THE SELECTOR IS LIVE, against clean source.
    $clean = runTests($c['test']);
    if (!$clean['executed']) {
        $results[] = $c + ['status' => 'BROKEN-SELECTOR', 'detail' => 'matches 0 tests'];
        continue;
    }
    if (!isSpotless($clean)) {
        $results[] = $c + [
            'status' => 'BROKEN-SELECTOR',
            'detail' => 'not spotless on clean source: ' . json_encode($clean['counts']),
        ];
        continue;
    }
    $covers = $clean['passed'];

    // 2. PROVE EVERY ANCHOR MATCHES EXACTLY ONCE, before touching anything.
    $originals = [];
    $anchorProblem = null;
    foreach ($c['edits'] as [$file, $find, $_replace]) {
        $originals[$file] ??= file_get_contents($file);
        $occurrences = substr_count($originals[$file], $find);
        if ($occurrences !== 1) {
            $anchorProblem = "anchor in {$file} matched {$occurrences}x";
            break;
        }
    }
    if ($anchorProblem !== null) {
        $results[] = $c + ['status' => 'BROKEN-ANCHOR', 'detail' => $anchorProblem];
        continue;
    }

    // 3. MUTATE.
    $restore = $originals;
    $mutated = $originals;
    foreach ($c['edits'] as [$file, $find, $replace]) {
        $mutated[$file] = str_replace($find, $replace, $mutated[$file]);
    }
    foreach ($mutated as $file => $contents) {
        file_put_contents($file, $contents);
    }

    // 4. RUN, and require a FAILURE.
    $after = runTests($c['test']);

    // 5. RESTORE, unconditionally.
    foreach ($originals as $file => $contents) {
        file_put_contents($file, $contents);
    }
    $restore = [];

    if (!$after['executed']) {
        // The mutation broke the suite so badly nothing ran. That is not a caught mutation.
        $results[] = $c + ['status' => 'BROKEN-MUTATION', 'detail' => 'no tests executed after mutation'];
        continue;
    }

    $caught = isGenuineCatch($after);
    $status = $caught ? 'CAUGHT' : ($after['code'] === 0 ? 'VACUOUS' : 'BROKEN-MUTATION');
    $results[] = $c + [
        'status' => $status,
        'detail' => ($caught ? "{$after['failed']} assertion failure(s)" : 'no genuine assertion '
            . 'failure: ' . json_encode($after['counts']))
            . "; selector covers {$covers} test(s)",
    ];
}

echo "\n| id | reverted protection | guarding test | result |\n";
echo "| --- | --- | --- | --- |\n";
foreach ($results as $r) {
    printf("| %s | %s | %s | %s (%s) |\n", $r['id'], $r['what'], $r['test'], $r['status'], $r['detail']);
}

$unregistered = unregisteredTags($CASES);
if ($unregistered !== []) {
    fwrite(STDERR, "UNREGISTERED nv: TAGS (a protection with a test but no mutation case): "
        . implode(', ', $unregistered) . "\n");
}

$bad = array_values(array_filter($results, static fn (array $r): bool => $r['status'] !== 'CAUGHT'));
printf("\n%d/%d mutations caught.\n", count($results) - count($bad), count($results));

if ($unregistered !== []) {
    exit(1);
}

if ($bad !== []) {
    fwrite(STDERR, 'NOT ALL MUTATIONS CAUGHT: ' . implode(', ', array_map(
        static fn (array $b): string => "{$b['id']}={$b['status']}",
        $bad,
    )) . "\n");
    exit(1);
}
