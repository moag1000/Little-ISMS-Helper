<?php

declare(strict_types=1);

namespace App\Tests\Service\Bsi;

use App\Service\Bsi\KompendiumGsppCoverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The mapping hub offers "Vorschläge importieren" while shipped supplement
 * pairs are missing; pairs whose requirements are not loaded must not keep the
 * offer alive, because an import could never add them.
 */
final class KompendiumGsppCoverageTest extends TestCase
{
    #[Test]
    public function counts_shipped_pairs_the_database_lacks(): void
    {
        $shipped = [['APP.1.1.A1', 'KONF.1.1'], ['APP.1.1.A1', 'KONF.2.1'], ['APP.1.1.A2', 'BER.1.1']];
        $imported = [['APP.1.1.A1', 'KONF.1.1']];

        self::assertSame(2, KompendiumGsppCoverage::missingPairs(
            $shipped,
            $imported,
            ['APP.1.1.A1', 'APP.1.1.A2'],
            ['KONF.1.1', 'KONF.2.1', 'BER.1.1'],
        ));
    }

    #[Test]
    public function ignores_pairs_whose_requirements_are_not_loaded(): void
    {
        $shipped = [['APP.1.1.A1', 'KONF.1.1'], ['APP.9.9.A9', 'KONF.1.1'], ['APP.1.1.A1', 'NEU.1.1']];

        self::assertSame(1, KompendiumGsppCoverage::missingPairs($shipped, [], ['APP.1.1.A1'], ['KONF.1.1']));
    }

    #[Test]
    public function zero_when_everything_is_imported(): void
    {
        $pairs = [['APP.1.1.A1', 'KONF.1.1']];

        self::assertSame(0, KompendiumGsppCoverage::missingPairs($pairs, $pairs, ['APP.1.1.A1'], ['KONF.1.1']));
    }
}
