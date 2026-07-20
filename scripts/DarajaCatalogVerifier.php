<?php

declare(strict_types=1);

namespace Paylod\Scripts;

use RuntimeException;

/**
 * Verification of the vendored Daraja catalog against `daraja-catalog.sha256`.
 *
 * ONE implementation, shared by the two guards that must agree:
 *   - `composer catalog-drift`  -> scripts/sync-daraja-catalog.php --check
 *   - tests/DarajaCatalogDriftTest.php
 *
 * WHY THIS EXISTS. Both guards used to compare the vendored copy against a sibling checkout of the
 * private paylod monorepo, and to SKIP when that checkout was absent. In CI it is always absent, so
 * both skipped and the pipeline stayed green while verifying nothing. A check that cannot
 * distinguish "I did not look" from "I looked and it is fine" is not a check.
 *
 * The fix is a committed digest, not a cross-repo token. A token would mean minting a long-lived
 * credential with read access to the whole private monorepo and storing it in four SDK repos, three
 * of them public - widening the blast radius to solve what is only a file-availability problem.
 *
 * Everything here FAILS CLOSED. A missing, unreadable, empty, or malformed checksum file is a
 * failure, never a skip. The sibling-monorepo comparison remains as an ADDITIONAL, stronger check
 * when the monorepo happens to be present; its absence downgrades nothing.
 */
final class DarajaCatalogVerifier
{
    public const CHECKSUM_FILE = 'daraja-catalog.sha256';

    private const CANONICAL_SUBPATH = 'supabase/functions/_shared/daraja/daraja-error-codes.json';

    public function __construct(private readonly string $sdkRoot)
    {
    }

    /** The monorepo root this machine would use, whether or not it exists. */
    public function monorepoRoot(): string
    {
        $env = getenv('MPESA_REPO');

        return ($env !== false && $env !== '')
            ? rtrim($env, '/')
            : dirname($this->sdkRoot) . '/mpesa';
    }

    /** True when a canonical catalog is actually readable beside this checkout. */
    public function monorepoIsPresent(): bool
    {
        return is_file($this->monorepoRoot() . '/' . self::CANONICAL_SUBPATH);
    }

    public function checksumPath(): string
    {
        return $this->sdkRoot . '/' . self::CHECKSUM_FILE;
    }

    /**
     * Parse the pinned checksum file.
     *
     * @return list<array{canonicalSha: string, vendoredSha: string, canonicalRel: string, vendoredRel: string}>
     *
     * @throws RuntimeException on anything short of a well-formed file with at least one data row
     */
    public function pinnedRows(): array
    {
        $path = $this->checksumPath();

        if (!is_file($path)) {
            throw new RuntimeException(
                "pinned checksum file is missing: {$path}\n"
                . 'This file is what lets the drift guard verify itself without the private monorepo. '
                . 'Regenerate it from the monorepo: node scripts/sync-daraja-catalog.mjs'
            );
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("pinned checksum file is unreadable: {$path}");
        }
        if (trim($raw) === '') {
            throw new RuntimeException("pinned checksum file is empty: {$path}");
        }

        $rows = [];
        foreach (explode("\n", $raw) as $lineNo => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $fields = preg_split('/\s+/', $trimmed);
            if (!is_array($fields) || count($fields) !== 4) {
                $n = is_array($fields) ? count($fields) : 0;
                throw new RuntimeException(
                    "malformed pinned checksum file {$path} line " . ($lineNo + 1)
                    . ": expected 4 whitespace-separated fields, found {$n}"
                );
            }

            [$canonicalSha, $vendoredSha, $canonicalRel, $vendoredRel] = $fields;
            foreach (['canonical' => $canonicalSha, 'vendored' => $vendoredSha] as $which => $digest) {
                if (preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
                    throw new RuntimeException(
                        "malformed pinned checksum file {$path} line " . ($lineNo + 1)
                        . ": {$which} digest is not a 64-character lowercase sha256: {$digest}"
                    );
                }
            }

            $rows[] = [
                'canonicalSha' => $canonicalSha,
                'vendoredSha' => $vendoredSha,
                'canonicalRel' => $canonicalRel,
                'vendoredRel' => $vendoredRel,
            ];
        }

        if ($rows === []) {
            throw new RuntimeException(
                "pinned checksum file has no data rows: {$path}\n"
                . 'A checksum file that pins nothing verifies nothing.'
            );
        }

        return $rows;
    }

    /**
     * ALWAYS-ON check: every vendored copy matches its pinned digest. No monorepo, no network.
     *
     * @return list<string> human-readable log lines, on success only
     *
     * @throws RuntimeException on any drift or missing vendored file
     */
    public function verifyPinned(): array
    {
        $log = [];
        foreach ($this->pinnedRows() as $row) {
            $path = $this->sdkRoot . '/' . $row['vendoredRel'];
            if (!is_file($path)) {
                throw new RuntimeException(
                    "vendored file listed in " . self::CHECKSUM_FILE . " is missing: {$row['vendoredRel']}"
                );
            }

            $actual = @hash_file('sha256', $path);
            if ($actual === false) {
                throw new RuntimeException("could not read vendored file: {$row['vendoredRel']}");
            }

            if ($actual !== $row['vendoredSha']) {
                throw new RuntimeException(
                    "[DRIFT] {$row['vendoredRel']} does not match its pinned digest.\n"
                    . "  pinned: {$row['vendoredSha']}\n"
                    . "  actual: {$actual}\n"
                    . 'The vendored Daraja catalog is GENERATED. Re-sync it from the monorepo rather '
                    . 'than hand-editing it: php scripts/sync-daraja-catalog.php'
                );
            }

            $log[] = "[ok] pinned digest matches  {$row['vendoredRel']}  ({$actual})";
        }

        return $log;
    }

    /**
     * ADDITIONAL check, only when the private monorepo is checked out beside this repo: the
     * canonical file still hashes to the pinned canonical digest, and (where the vendored copy is
     * meant to be a verbatim copy) the bytes are identical.
     *
     * @return list<string> human-readable log lines, on success only
     *
     * @throws RuntimeException when the monorepo is present but disagrees
     */
    public function verifyAgainstCanonical(): array
    {
        $root = $this->monorepoRoot();
        $log = [];

        foreach ($this->pinnedRows() as $row) {
            $canonical = $root . '/' . $row['canonicalRel'];
            if (!is_file($canonical)) {
                throw new RuntimeException(
                    "monorepo is present at {$root} but the canonical file is missing: {$row['canonicalRel']}"
                );
            }

            $canonicalSha = @hash_file('sha256', $canonical);
            if ($canonicalSha === false) {
                throw new RuntimeException("could not read canonical file: {$canonical}");
            }

            if ($canonicalSha !== $row['canonicalSha']) {
                throw new RuntimeException(
                    "[DRIFT] the canonical catalog has moved on from the pinned digest.\n"
                    . "  canonical: {$row['canonicalRel']} ({$canonicalSha})\n"
                    . "  pinned:    {$row['canonicalSha']}\n"
                    . 'Re-sync from the monorepo: node scripts/sync-daraja-catalog.mjs'
                );
            }

            // A pinned row whose two digests are equal declares the vendored copy to be a verbatim
            // copy. Where that holds, compare the bytes as well - it is the strongest statement
            // available and does not depend on the digests being computed the same way.
            if ($row['canonicalSha'] === $row['vendoredSha']) {
                $vendored = $this->sdkRoot . '/' . $row['vendoredRel'];
                if (@file_get_contents($vendored) !== @file_get_contents($canonical)) {
                    throw new RuntimeException(
                        "[DRIFT] {$row['vendoredRel']} is not byte-identical to {$canonical}.\n"
                        . 'Run: php scripts/sync-daraja-catalog.php'
                    );
                }
            }

            $log[] = "[ok] canonical provenance matches  {$row['canonicalRel']}  ({$canonicalSha})";
        }

        return $log;
    }

    /**
     * The full guard: the pinned check ALWAYS, plus the canonical comparison when it is possible.
     *
     * @return list<string> human-readable log lines, on success only
     *
     * @throws RuntimeException on any failure
     */
    public function verify(): array
    {
        $log = $this->verifyPinned();

        if ($this->monorepoIsPresent()) {
            return array_merge($log, $this->verifyAgainstCanonical());
        }

        $log[] = '[note] the paylod monorepo is not checked out at ' . $this->monorepoRoot()
            . ' - the canonical comparison was NOT possible.';
        $log[] = '[note] the pinned-checksum verification above WAS performed and passed. This run '
            . 'is not a skip.';

        return $log;
    }
}
