<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GlossaryService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Backs the fa-glossary-tooltip component: a junior ISB hovering an acronym
 * gets a short definition + norm reference instead of bare jargon. These tests
 * guard the contract the Twig macro + /api/glossary endpoint rely on.
 */
final class GlossaryServiceTest extends TestCase
{
    private function service(): GlossaryService
    {
        return new GlossaryService(\dirname(__DIR__, 2) . '/config/glossary.yaml');
    }

    #[Test]
    public function lookupReturnsGermanDefinitionByDefault(): void
    {
        $entry = $this->service()->lookup('Bedrohung');

        self::assertNotNull($entry);
        self::assertSame('Bedrohung', $entry['acronym']);
        self::assertNotSame('', $entry['definition']);
    }

    #[Test]
    public function lookupIsCaseAndWhitespaceInsensitive(): void
    {
        $a = $this->service()->lookup('  soa  ');
        $b = $this->service()->lookup('SoA');

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertSame($b['acronym'], $a['acronym']);
    }

    #[Test]
    public function lookupHonoursEnglishLocale(): void
    {
        $de = $this->service()->lookup('Restrisiko', 'de');
        $en = $this->service()->lookup('Restrisiko', 'en');

        self::assertNotNull($de);
        self::assertNotNull($en);
        self::assertNotSame($de['definition'], $en['definition']);
    }

    #[Test]
    public function unknownTermReturnsNull(): void
    {
        self::assertNull($this->service()->lookup('NotARealAcronym'));
    }

    #[Test]
    public function allReturnsSortedNonEmptyCatalog(): void
    {
        $all = $this->service()->all();

        self::assertNotEmpty($all);

        $acronyms = array_map(static fn (array $e): string => $e['acronym'], $all);
        $sorted = $acronyms;
        usort($sorted, 'strcasecmp');
        self::assertSame($sorted, $acronyms, 'all() must be alphabetically sorted');
    }

    #[Test]
    public function missingCatalogYieldsEmptyResultNotError(): void
    {
        $svc = new GlossaryService('/no/such/glossary.yaml');

        self::assertNull($svc->lookup('SoA'));
        self::assertSame([], $svc->all());
    }
}
