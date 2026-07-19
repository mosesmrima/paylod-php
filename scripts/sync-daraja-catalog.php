<?php

declare(strict_types=1);

/**
 * Pull THE Daraja code table into this SDK from its canonical home.
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
 *   php scripts/sync-daraja-catalog.php           # write the copy
 *   php scripts/sync-daraja-catalog.php --check   # exit 1 if the copy has drifted (CI)
 *
 * The monorepo checkout is found at ../mpesa by default; override with MPESA_REPO=/path.
 */

$sdk = dirname(__DIR__);
$mpesaEnv = getenv('MPESA_REPO');
$mpesa = ($mpesaEnv !== false && $mpesaEnv !== '') ? $mpesaEnv : dirname($sdk) . '/mpesa';
$srcDir = rtrim($mpesa, '/') . '/supabase/functions/_shared/daraja';

$canonical = $srcDir . '/daraja-error-codes.json';
$vendored = $sdk . '/src/resources/daraja-error-codes.json';

$check = in_array('--check', array_slice($argv, 1), true);

/** Short content fingerprint, for human-readable drift reporting. */
$sha = static fn (string $s): string => substr(hash('sha256', $s), 0, 12);

if (!is_dir($srcDir) || !is_file($canonical)) {
    // A published-package consumer, or a CI job without the monorepo checked out, cannot sync. The
    // vendored copy is committed, so that is fine - just do not pretend we verified it.
    $msg = "paylod monorepo not found at {$mpesa} (set MPESA_REPO=/path/to/mpesa)";
    if ($check) {
        fwrite(STDERR, "[warn] skipping drift check: {$msg}\n");
        exit(0);
    }
    fwrite(STDERR, "[FAIL] {$msg}\n");
    exit(1);
}

$want = file_get_contents($canonical);
if ($want === false) {
    fwrite(STDERR, "[FAIL] could not read canonical catalog at {$canonical}\n");
    exit(1);
}

$have = is_file($vendored) ? file_get_contents($vendored) : null;
if ($have === false) {
    fwrite(STDERR, "[FAIL] could not read vendored catalog at {$vendored}\n");
    exit(1);
}

$rel = 'src/resources/daraja-error-codes.json';

if ($have === $want) {
    fwrite(STDOUT, "[ok] up to date  {$rel}  ({$sha($want)})\n");
    fwrite(STDOUT, $check ? "\nThe vendored copy matches the canonical catalog.\n" : "\nSynced.\n");
    exit(0);
}

if ($check) {
    $hadDesc = $have === null ? 'missing' : $sha($have);
    fwrite(STDERR, "[DRIFT] {$rel}  (has {$hadDesc}, want {$sha($want)})\n");
    fwrite(
        STDERR,
        "\nThe vendored Daraja catalog has drifted from its canonical source.\n"
        . "Run: php scripts/sync-daraja-catalog.php\n"
    );
    exit(1);
}

if (file_put_contents($vendored, $want) === false) {
    fwrite(STDERR, "[FAIL] could not write {$vendored}\n");
    exit(1);
}
fwrite(STDOUT, "[ok] wrote  {$rel}  ({$sha($want)})\n\nSynced.\n");
exit(0);
