<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\DoraExtensionCatalogue;
use App\Service\PolicyWizard\GdprSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtensionRegistry;
use App\Service\PolicyWizard\SectionExtension\StandardSectionCatalogueInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see SectionExtensionRegistry}.
 *
 * Uses the two real catalogues ({@see GdprSectionCatalogue} and
 * {@see DoraExtensionCatalogue}) so the registry contract is tested
 * against actual implementations rather than stubs.
 */
final class SectionExtensionRegistryTest extends TestCase
{
    private SectionExtensionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new SectionExtensionRegistry([
            new GdprSectionCatalogue(),
            new DoraExtensionCatalogue(),
        ]);
    }

    #[Test]
    public function forStandardGdprResolvesToGdprCatalogue(): void
    {
        $catalogue = $this->registry->forStandard('gdpr');

        self::assertInstanceOf(
            GdprSectionCatalogue::class,
            $catalogue,
            'forStandard("gdpr") must resolve to GdprSectionCatalogue',
        );
        self::assertSame('gdpr', $catalogue->getStandard());
    }

    #[Test]
    public function forStandardDoraResolvesToDoraExtensionCatalogue(): void
    {
        $catalogue = $this->registry->forStandard('dora');

        self::assertInstanceOf(
            DoraExtensionCatalogue::class,
            $catalogue,
            'forStandard("dora") must resolve to DoraExtensionCatalogue',
        );
        self::assertSame('dora', $catalogue->getStandard());
    }

    #[Test]
    public function forStandardUnknownReturnsNull(): void
    {
        self::assertNull($this->registry->forStandard('xxx'));
        self::assertNull($this->registry->forStandard(''));
        self::assertNull($this->registry->forStandard('iso27001'));
        self::assertNull($this->registry->forStandard('bsi'));
        self::assertNull($this->registry->forStandard('nis2'));
    }

    #[Test]
    public function allReturnsIterableWithBothCatalogues(): void
    {
        $standards = [];
        foreach ($this->registry->all() as $catalogue) {
            self::assertInstanceOf(StandardSectionCatalogueInterface::class, $catalogue);
            $standards[] = $catalogue->getStandard();
        }

        self::assertContains('gdpr', $standards, 'all() must yield the GDPR catalogue');
        self::assertContains('dora', $standards, 'all() must yield the DORA catalogue');
        self::assertCount(2, $standards);
    }

    #[Test]
    public function forStandardIsCaseSensitive(): void
    {
        // 'GDPR' and 'DORA' (uppercase) must NOT match — standard tokens
        // are lowercase throughout WizardRun.standardsAdopted.
        self::assertNull($this->registry->forStandard('GDPR'));
        self::assertNull($this->registry->forStandard('DORA'));
        self::assertNull($this->registry->forStandard('Gdpr'));
    }

    #[Test]
    public function emptyRegistryReturnsNullForAnyStandard(): void
    {
        $emptyRegistry = new SectionExtensionRegistry([]);
        self::assertNull($emptyRegistry->forStandard('gdpr'));
        self::assertNull($emptyRegistry->forStandard('dora'));
    }

    #[Test]
    public function registryWithSingleCatalogueReturnsNullForOther(): void
    {
        $registry = new SectionExtensionRegistry([new GdprSectionCatalogue()]);
        self::assertInstanceOf(GdprSectionCatalogue::class, $registry->forStandard('gdpr'));
        self::assertNull($registry->forStandard('dora'), 'DORA catalogue not registered → null');
    }
}
