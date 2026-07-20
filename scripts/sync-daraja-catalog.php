<?php

declare(strict_types=1);

/**
 * Pull THE Daraja code table into this SDK from its canonical home, and verify the copy.
 *
 * Canonical (edit this, and ONLY this - it lives in the paylod monorepo):
 *   supabase/functions/_shared/daraja/daraja-error-codes.json
 *
 * This SDK is a separate git repo and a separate publish artifact, so - exactly like the Node SDK
 * and `paylod-mcp` - it cannot import across the repo boundary and must carry a physical copy.
 * That copy is GENERATED. Never hand-edit `src/resources/daraja-error-codes.json`.
 *
 * WHY THIS SCRIPT EXISTS: a hand-maintained copy of this table is what shipped the 4999
 * "false failure / double charge" bug - twice. A vendored copy with no mechanical link to its
 * source does not stay in sync; it drifts silently, and the drift is only discovered by a customer
 * being charged twice.
 *
 * WHY --check NO LONGER SKIPS: it used to compare against a sibling checkout of the private
 * monorepo and exit 0 when that checkout was absent. In CI it is ALWAYS absent, so the check
 * skipped and the pipeline stayed green while verifying nothing. A check that cannot distinguish
 * "I did not look" from "I looked and it is fine" is not a check. --check now verifies the
 * vendored copy against the committed digests in `daraja-catalog.sha256`, which works in any
 * checkout with no sibling repo and no credential, and additionally compares against the canonical
 * file when the monorepo does happen to be present.
 *
 *   php scripts/sync-daraja-catalog.php           # write the copy + regenerate daraja-catalog.sha256
 *   php scripts/sync-daraja-catalog.php --check   # exit 1 if the copy has drifted (CI)
 *
 * The monorepo checkout is found at ../mpesa by default; override with MPESA_REPO=/path. Write mode
 * requires it. --check does not.
 */

require_once __DIR__ . '/DarajaCatalogVerifier.php';

use Paylod\Scripts\DarajaCatalogVerifier;

$sdk = dirname(__DIR__);
$verifier = new DarajaCatalogVerifier($sdk);
$check = in_array('--check', array_slice($argv, 1), true);

// -- --check: verify, never skip ----------------------------------------------------------------
if ($check) {
    try {
        foreach ($verifier->verify() as $line) {
            fwrite(STDOUT, $line . "\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
        exit(1);
    }

    fwrite(STDOUT, "\nThe vendored Daraja catalog was verified.\n");
    exit(0);
}

// -- write mode: copy from canonical, then regenerate the pinned checksum file -------------------
$mpesa = $verifier->monorepoRoot();
if (!$verifier->monorepoIsPresent()) {
    fwrite(
        STDERR,
        "[FAIL] paylod monorepo not found at {$mpesa} (set MPESA_REPO=/path/to/mpesa)\n"
        . "Write mode copies FROM the canonical catalog, so it cannot run without it.\n"
        . "To merely verify the committed copy, run: php scripts/sync-daraja-catalog.php --check\n"
    );
    exit(1);
}

/**
 * [canonical relpath in the monorepo, vendored relpath in this SDK].
 *
 * This SDK vendors the table verbatim - no banner, no transform - so canonical and vendored bytes
 * are identical and the two pinned digests agree. Kept as a list so the shape matches the
 * monorepo's generator and the other SDKs.
 */
$COPIES = [
    ['supabase/functions/_shared/daraja/daraja-error-codes.json', 'src/resources/daraja-error-codes.json'],
];

/**
 * The pinned-checksum header, byte-for-byte as the monorepo's scripts/sync-daraja-catalog.mjs
 * emits it. If that generator's header changes, this one must change with it - the monorepo
 * rewrites this file for every SDK, and a divergent header would show up as a permanent diff.
 */
$CHECKSUM_HEADER = <<<'TXT'
# Daraja catalog provenance — PINNED CHECKSUMS. GENERATED FILE, DO NOT HAND-EDIT.
#
# Regenerate from the paylod monorepo:  node scripts/sync-daraja-catalog.mjs
#
# This repo's drift guard verifies the vendored catalog against these digests, with no access
# to the canonical file. That is deliberate. The canonical catalog lives in a PRIVATE monorepo
# which CI cannot check out, and the guard used to compare against a sibling directory that
# does not exist in CI — so it skipped, silently, and the pipeline stayed green while verifying
# nothing. A check that cannot distinguish "I did not look" from "I looked and it is fine" is
# not a check.
#
# A cross-repo token was rejected as the fix: it would mint a long-lived credential with read
# access to the private monorepo and store it in four SDK repos, three of them public. A
# committed digest needs no credential, no network, and works in any checkout.
#
# Fields: <canonical sha256>  <vendored sha256>  <canonical path>  <vendored path>
TXT;

$short = static fn (string $s): string => substr(hash('sha256', $s), 0, 12);
$rows = [];

foreach ($COPIES as [$canonicalRel, $vendoredRel]) {
    $canonicalPath = $mpesa . '/' . $canonicalRel;
    $vendoredPath = $sdk . '/' . $vendoredRel;

    $canonical = @file_get_contents($canonicalPath);
    if ($canonical === false) {
        fwrite(STDERR, "[FAIL] could not read canonical catalog at {$canonicalPath}\n");
        exit(1);
    }

    $have = is_file($vendoredPath) ? @file_get_contents($vendoredPath) : null;
    if ($have === $canonical) {
        fwrite(STDOUT, "[ok] up to date  {$vendoredRel}  ({$short($canonical)})\n");
    } else {
        $dir = dirname($vendoredPath);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true)) {
            fwrite(STDERR, "[FAIL] could not create {$dir}\n");
            exit(1);
        }
        if (@file_put_contents($vendoredPath, $canonical) === false) {
            fwrite(STDERR, "[FAIL] could not write {$vendoredPath}\n");
            exit(1);
        }
        fwrite(STDOUT, "[ok] wrote  {$vendoredRel}  ({$short($canonical)})\n");
    }

    // Recorded from the CANONICAL bytes, never from whatever is on disk here. Hashing the copy we
    // just wrote back would make the record a tautology; hashing the source makes it provenance
    // the copy has to live up to.
    $digest = hash('sha256', $canonical);
    $rows[] = "{$digest}  {$digest}  {$canonicalRel}  {$vendoredRel}";
}

$wantSum = $CHECKSUM_HEADER . "\n" . implode("\n", $rows) . "\n";
$sumPath = $verifier->checksumPath();
$haveSum = is_file($sumPath) ? @file_get_contents($sumPath) : null;

if ($haveSum === $wantSum) {
    fwrite(STDOUT, '[ok] up to date  ' . DarajaCatalogVerifier::CHECKSUM_FILE . "\n");
} elseif (@file_put_contents($sumPath, $wantSum) === false) {
    fwrite(STDERR, "[FAIL] could not write {$sumPath}\n");
    exit(1);
} else {
    fwrite(STDOUT, '[ok] wrote  ' . DarajaCatalogVerifier::CHECKSUM_FILE . "\n");
}

fwrite(STDOUT, "\nSynced.\n");
exit(0);
