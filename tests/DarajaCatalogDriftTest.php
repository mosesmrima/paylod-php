<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\DarajaCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The vendored Daraja catalog is a COPY. Copies drift, and a drifted copy of this particular table
 * is what shipped the 4999 "false failure / double charge" bug twice. These guards make the drift
 * mechanical rather than discovered-by-customer.
 *
 * The drift guard skips when the monorepo is not checked out (a published-package consumer cannot
 * verify a source it does not have). Skipping silently is acceptable; PASSING silently while the
 * copy differs is not.
 */
final class DarajaCatalogDriftTest extends TestCase
{
    private const VENDORED = __DIR__ . '/../src/resources/daraja-error-codes.json';

    /** Absolute path to the canonical table, or null when the monorepo is not available. */
    private static function canonicalPath(): ?string
    {
        $env = getenv('MPESA_REPO');
        $mpesa = ($env !== false && $env !== '')
            ? $env
            : dirname(__DIR__, 2) . '/mpesa';
        $path = rtrim($mpesa, '/') . '/supabase/functions/_shared/daraja/daraja-error-codes.json';

        return is_file($path) ? $path : null;
    }

    public function testVendoredCatalogIsByteIdenticalToCanonical(): void
    {
        $canonical = self::canonicalPath();
        if ($canonical === null) {
            self::markTestSkipped(
                'paylod monorepo not checked out - cannot verify the vendored Daraja catalog against '
                . 'its canonical source. Set MPESA_REPO=/path/to/mpesa to run this guard.'
            );
        }

        $want = file_get_contents($canonical);
        $have = file_get_contents(self::VENDORED);
        self::assertIsString($want, "could not read canonical catalog at {$canonical}");
        self::assertIsString($have, 'could not read vendored catalog');

        self::assertSame(
            $want,
            $have,
            "src/resources/daraja-error-codes.json has DRIFTED from the canonical catalog at {$canonical}.\n"
            . 'Run: php scripts/sync-daraja-catalog.php'
        );
    }

    /**
     * (code, family) is the real key. `code` alone is ambiguous - three codes appear under two
     * different families with materially different meanings, and in one case with opposite
     * `retryable` verdicts. A duplicate (code, family) PAIR, by contrast, is a catalog bug: it
     * would make lookup order-dependent.
     */
    public function testEveryCodeFamilyPairIsUnique(): void
    {
        $seen = [];
        foreach (DarajaCatalog::allEntries() as $entry) {
            $pair = (string) $entry['code'] . '|' . (string) ($entry['family'] ?? '');
            self::assertArrayNotHasKey(
                $pair,
                $seen,
                "duplicate (code, family) pair in the Daraja catalog: {$pair}"
            );
            $seen[$pair] = true;
        }

        self::assertSame(
            count(DarajaCatalog::allEntries()),
            count($seen),
            'every catalog entry must contribute a distinct (code, family) pair'
        );
    }

    /**
     * Non-vacuity for the guard above. If the catalog had no duplicate bare `code` values at all,
     * the uniqueness assertion would be trivially true and would document nothing. This test pins
     * the ambiguity that makes (code, family) the necessary key.
     */
    public function testDuplicateBareCodesDoExist(): void
    {
        $byCode = [];
        foreach (DarajaCatalog::allEntries() as $entry) {
            $byCode[(string) $entry['code']][] = (string) ($entry['family'] ?? '');
        }

        // PHP coerces numeric-string array keys to integers, so cast back before comparing.
        $duplicated = array_map(
            'strval',
            array_keys(array_filter($byCode, static fn (array $f): bool => count($f) > 1))
        );
        sort($duplicated);

        // These three are the reason `code` alone cannot be the key.
        self::assertSame(['0', '2001', '500.001.1001'], $duplicated);

        // Each collision is split across the STK surface and exactly one non-STK surface.
        $expectedFamilies = [
            '0' => ['b2c_c2b_result', 'stk_result'],
            '2001' => ['b2c_c2b_result', 'stk_result'],
            '500.001.1001' => ['api_error', 'stk_result'],
        ];
        foreach ($duplicated as $code) {
            $families = $byCode[$code];
            sort($families);
            self::assertSame(
                $expectedFamilies[$code],
                $families,
                "code {$code} is expected to be split across the STK and non-STK surfaces"
            );
        }
    }

    /**
     * `errorCatalog()` is a code-keyed convenience view and therefore CANNOT represent the
     * ambiguity above. Its documented collision rule is "STK wins", because STK is the payment
     * path. Changing that shape would be a breaking API change, so it is pinned here instead:
     * silent reordering of the JSON must not silently flip which entry a caller sees.
     */
    public function testErrorCatalogResolvesCollisionsToTheStkEntry(): void
    {
        $catalog = DarajaCatalog::errorCatalog();

        // 2001: STK = wrong PIN (customer, retryable). b2c_c2b = initiator credential failure.
        self::assertSame('customer', $catalog['2001']['category']);
        self::assertTrue($catalog['2001']['retryable']);
        self::assertSame('stk_result', $catalog['2001']['family']);

        // 500.001.1001: STK = pending. api_error = terminal mpesa_system error.
        self::assertSame('pending', $catalog['500.001.1001']['category']);
        self::assertSame('stk_result', $catalog['500.001.1001']['family']);

        // 0: success on both surfaces, but still resolved STK-first.
        self::assertSame('stk_result', $catalog['0']['family']);
    }
}
