<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\DoraExtensionCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W4-A — DoraExtensionCatalogue unit tests.
 *
 * Verifies the 18-entry mapping (17 EXTENDS + 1 REPLACES) against the
 * §10 cross-mapping in `docs/plans/policy-wizard/03-dora-input.md`.
 */
final class DoraExtensionCatalogueTest extends TestCase
{
    #[Test]
    public function testExtensionMappingComplete(): void
    {
        $catalogue = new DoraExtensionCatalogue();

        // §10 cross-mapping: 17 EXTENDS + 1 REPLACES (network_security)
        // = 18 ISO topics that grow a DORA-Erweiterung section.
        self::assertSame(
            18,
            $catalogue->count(),
            'catalogue must have exactly 18 entries per DORA §10 (17 EXTENDS + 1 REPLACES)',
        );

        // Every entry must yield a non-empty article-ref list — defends
        // against accidental `=> []` regressions.
        foreach ($catalogue->all() as $topic => $articles) {
            self::assertIsString($topic);
            self::assertNotEmpty($articles, sprintf('topic "%s" must list at least one article', $topic));
            foreach ($articles as $article) {
                self::assertIsString($article);
                self::assertStringStartsWith('Art. ', $article, 'article refs follow "Art. X" convention');
            }
        }

        // Network Security must be present — it is the single REPLACES.
        self::assertArrayHasKey(
            'network_security',
            $catalogue->all(),
            'network_security is the single REPLACES entry (DORA Art. 9.4 stricter than A.8.20-A.8.23)',
        );
    }

    #[Test]
    public function testGetExtensionForKnownTopic(): void
    {
        $catalogue = new DoraExtensionCatalogue();

        // backup → Art. 12 (DORA §2.13 vs ISO A.8.13)
        $backup = $catalogue->getExtensionFor('backup');
        self::assertSame(['Art. 12'], $backup);

        // cryptography → Art. 9.4.b (DORA §2.7 vs ISO A.8.24)
        $crypto = $catalogue->getExtensionFor('cryptography');
        self::assertSame(['Art. 9.4.b'], $crypto);

        // incident_management lists 7 articles — full Art. 17-23 spread.
        $incidents = $catalogue->getExtensionFor('incident_management');
        self::assertIsArray($incidents);
        self::assertCount(7, $incidents);
        self::assertSame('Art. 17', $incidents[0]);
        self::assertSame('Art. 23', $incidents[6]);

        // network_security → Art. 9.4 (REPLACES — single article ref)
        $network = $catalogue->getExtensionFor('network_security');
        self::assertSame(['Art. 9.4'], $network);
    }

    #[Test]
    public function testGetExtensionForUnknownTopicReturnsNull(): void
    {
        $catalogue = new DoraExtensionCatalogue();

        // ISO topics that have no DORA equivalent — must return null,
        // never an empty list (so DocumentGenerator's `=== null` check
        // distinguishes "no extension" from "empty extension").
        self::assertNull($catalogue->getExtensionFor('compliance_review'));
        self::assertNull($catalogue->getExtensionFor('internal_audit_programme'));
        self::assertNull($catalogue->getExtensionFor('does_not_exist'));
        self::assertNull($catalogue->getExtensionFor(''));

        // Sanity: risk_appetite is intentionally NOT in the catalogue
        // (DORA §2.2 — standalone document, no Annex A anchor; covered
        // by the standalone `dora.ict_risk_tolerance` template).
        self::assertNull(
            $catalogue->getExtensionFor('risk_appetite'),
            'risk_appetite stays as a standalone DORA template, not an extension',
        );
    }
}
