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
                "                self::EVIDENCE_SUCCESS => \$of(\n                    self::VERDICT_INDETERMINATE,\n                    'status says pending while the evidence says the payment succeeded - a pending '\n                    . 'record must never be reported as paid'\n                ),",
                "                self::EVIDENCE_SUCCESS => \$of(self::VERDICT_PAID, 'REVERTED'),",
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
                "        if (\$codeEvidence === self::EVIDENCE_SUCCESS || \$codeEvidence === self::EVIDENCE_NONE) {\n            return self::EVIDENCE_SUCCESS;\n        }\n\n        return self::EVIDENCE_CONFLICT;",
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
                "                self::EVIDENCE_NONE => \$of(\n                    self::VERDICT_INDETERMINATE,\n                    'status claims success but the record carries neither a receipt nor a result '\n                    . 'code, so there is no evidence the payment actually settled'\n                ),",
                "                self::EVIDENCE_NONE => \$of(self::VERDICT_PAID, 'REVERTED'),",
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
                '        if ($verdict === Semantics::VERDICT_INDETERMINATE) {',
                '        if (false) {',
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
];

/**
 * Run PHPUnit for one selector.
 *
 * @return array{code:int, passed:int, failed:int, executed:bool}
 */
function runTests(string $selector): array
{
    $cmd = 'vendor/bin/phpunit --filter ' . escapeshellarg('/::' . preg_quote($selector, '/') . '$/')
        . ' --do-not-cache-result 2>&1';
    exec($cmd, $lines, $code);
    $out = implode("\n", $lines);

    $passed = 0;
    $failed = 0;
    // "OK (12 tests, 30 assertions)" on a clean run.
    if (preg_match('/OK \((\d+) test/', $out, $m) === 1) {
        $passed = (int) $m[1];
    }
    // "Tests: 12, Assertions: 30, Failures: 1." on a failing run.
    if (preg_match('/Tests: (\d+),/', $out, $m) === 1) {
        $passed = max($passed, (int) $m[1]);
    }
    if (preg_match('/(?:Failures|Errors): (\d+)/', $out, $m) === 1) {
        $failed = (int) $m[1];
    }
    // THE TRAP: PHPUnit exits 0 and says this when the filter matched nothing.
    $executed = !str_contains($out, 'No tests executed!') && $passed > 0;

    return ['code' => $code, 'passed' => $passed, 'failed' => $failed, 'executed' => $executed];
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
    if ($clean['code'] !== 0) {
        $results[] = $c + ['status' => 'BROKEN-SELECTOR', 'detail' => 'fails on clean source'];
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

    $caught = $after['code'] !== 0;
    $results[] = $c + [
        'status' => $caught ? 'CAUGHT' : 'VACUOUS',
        'detail' => ($caught ? "{$after['failed']} test(s) failed" : 'test still PASSED')
            . "; selector covers {$covers} test(s)",
    ];
}

echo "\n| id | reverted protection | guarding test | result |\n";
echo "| --- | --- | --- | --- |\n";
foreach ($results as $r) {
    printf("| %s | %s | %s | %s (%s) |\n", $r['id'], $r['what'], $r['test'], $r['status'], $r['detail']);
}

$bad = array_values(array_filter($results, static fn (array $r): bool => $r['status'] !== 'CAUGHT'));
printf("\n%d/%d mutations caught.\n", count($results) - count($bad), count($results));

if ($bad !== []) {
    fwrite(STDERR, 'NOT ALL MUTATIONS CAUGHT: ' . implode(', ', array_map(
        static fn (array $b): string => "{$b['id']}={$b['status']}",
        $bad,
    )) . "\n");
    exit(1);
}
