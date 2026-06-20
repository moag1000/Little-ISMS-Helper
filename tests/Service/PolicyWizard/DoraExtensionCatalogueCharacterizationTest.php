<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\DoraExtensionCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests — pin the exact current output of DoraExtensionCatalogue
 * so any accidental regression in the production class is caught immediately.
 *
 * These tests MUST NOT change the production class; they describe it.
 */
final class DoraExtensionCatalogueCharacterizationTest extends TestCase
{
    private DoraExtensionCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->catalogue = new DoraExtensionCatalogue();
    }

    #[Test]
    public function countIsExactlyEighteen(): void
    {
        self::assertSame(18, $this->catalogue->count());
    }

    #[Test]
    public function allContainsExpectedTopicKeys(): void
    {
        $all = $this->catalogue->all();
        self::assertArrayHasKey('backup', $all);
        self::assertArrayHasKey('network_security', $all);
        self::assertArrayHasKey('incident_management', $all);
        self::assertArrayHasKey('supplier_security', $all);
        self::assertArrayHasKey('cryptography', $all);
        self::assertArrayHasKey('top_level', $all);
    }

    #[Test]
    public function getExtensionForBackupReturnsExactArticles(): void
    {
        $articles = $this->catalogue->getExtensionFor('backup');

        self::assertSame(['Art. 12'], $articles);
    }

    #[Test]
    public function getExtensionForIncidentManagementReturnsSevenArticles(): void
    {
        $articles = $this->catalogue->getExtensionFor('incident_management');

        self::assertIsArray($articles);
        self::assertCount(7, $articles);
        self::assertSame('Art. 17', $articles[0]);
        self::assertSame('Art. 18', $articles[1]);
        self::assertSame('Art. 19', $articles[2]);
        self::assertSame('Art. 20', $articles[3]);
        self::assertSame('Art. 21', $articles[4]);
        self::assertSame('Art. 22', $articles[5]);
        self::assertSame('Art. 23', $articles[6]);
    }

    #[Test]
    public function getExtensionForNetworkSecurityReturnsSingleArticle(): void
    {
        $articles = $this->catalogue->getExtensionFor('network_security');

        self::assertSame(['Art. 9.4'], $articles);
    }

    #[Test]
    public function getExtensionForUnknownTopicReturnsNull(): void
    {
        self::assertNull($this->catalogue->getExtensionFor('compliance_review'));
        self::assertNull($this->catalogue->getExtensionFor('internal_audit_programme'));
        self::assertNull($this->catalogue->getExtensionFor(''));
        self::assertNull($this->catalogue->getExtensionFor('risk_appetite'));
        self::assertNull($this->catalogue->getExtensionFor('___unknown___'));
    }
}
