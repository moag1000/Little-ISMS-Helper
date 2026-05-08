<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PolicyTemplate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W6-B — PolicyTemplate ISO 27701 PIMS clause columns.
 *
 * Validates the new `iso27701Clauses2025` + `iso27701Clauses2019` JSON
 * columns added by Version20260508161000_policy_wizard_w6_iso27701_metadata:
 *
 *   • getter / setter happy path with both versions independently
 *   • JSON round-trip (list ordering preserved, null/empty distinct)
 *   • both versions can coexist on the same template
 */
final class PolicyTemplateIso27701ClausesTest extends TestCase
{
    #[Test]
    public function testIso27701ClausesGetterSetter(): void
    {
        $template = new PolicyTemplate();

        // Default is null on both columns (template applies independent of PIMS).
        self::assertNull($template->getIso27701Clauses2025());
        self::assertNull($template->getIso27701Clauses2019());

        $template->setIso27701Clauses2025(['5.1', '5.2']);
        self::assertSame(['5.1', '5.2'], $template->getIso27701Clauses2025());
        // 2019 column is independent — still null.
        self::assertNull($template->getIso27701Clauses2019());

        $template->setIso27701Clauses2019(['5.1', '5.2']);
        self::assertSame(['5.1', '5.2'], $template->getIso27701Clauses2019());

        // Re-setting to null clears the column.
        $template->setIso27701Clauses2025(null);
        self::assertNull($template->getIso27701Clauses2025());
        // 2019 column unaffected.
        self::assertSame(['5.1', '5.2'], $template->getIso27701Clauses2019());
    }

    #[Test]
    public function testJsonRoundTripPreservesOrderAndDistinguishesEmptyFromNull(): void
    {
        $template = new PolicyTemplate();

        // Full DSR Procedure clause range — order must round-trip.
        $dsr = ['7.3.1', '7.3.2', '7.3.3', '7.3.4', '7.3.5', '7.3.6', '7.3.7', '7.3.8', '7.3.9', '7.3.10'];
        $template->setIso27701Clauses2025($dsr);
        $hydrated = $template->getIso27701Clauses2025();
        self::assertSame($dsr, $hydrated, 'list ordering must round-trip');
        self::assertCount(10, $hydrated);

        // Empty array preserved (distinct from null — caller may want
        // "set but no clauses" e.g. for thin host stubs).
        $template->setIso27701Clauses2025([]);
        self::assertSame([], $template->getIso27701Clauses2025());
        self::assertNotNull($template->getIso27701Clauses2025(), 'empty array is not null');

        $template->setIso27701Clauses2025(null);
        self::assertNull($template->getIso27701Clauses2025());
    }

    #[Test]
    public function testBothVersionsCanCoexistWithDifferentClauseLists(): void
    {
        // Spec §3.1 — Data-Breach Notification: Cl. 6.13 (2025) was
        // sub-clause 6.13.1.5 (2019). Edition delta MUST be storable
        // independently per column.
        $template = new PolicyTemplate();
        $template->setKey('gdpr.data_breach_notification_procedure');
        $template->setStandard('gdpr');
        $template->setTopic('data_breach_notification_procedure');
        $template->setDocumentType('procedure');
        $template->setTitleTranslationKey('policy.gdpr.data_breach_notification_procedure.v1.title');
        $template->setBodyTranslationKey('policy.gdpr.data_breach_notification_procedure.v1.body');
        $template->setIso27701Clauses2025(['6.13']);
        $template->setIso27701Clauses2019(['6.13.1.5']);

        // 2025 promoted; 2019 was deeper.
        self::assertSame(['6.13'], $template->getIso27701Clauses2025());
        self::assertSame(['6.13.1.5'], $template->getIso27701Clauses2019());
        // Edition delta confirmed.
        self::assertNotSame(
            $template->getIso27701Clauses2025(),
            $template->getIso27701Clauses2019(),
            '2025 vs 2019 edition delta must be storable distinctly per column',
        );

        // The 2019 → 2025 change for breach response is a row-by-row
        // anomaly per spec §3.2; other rows (RoPA Cl. 7.2.8) are
        // unchanged. Confirm both shapes coexist on the same instance.
        $template->setIso27701Clauses2025(['7.2.8']);
        $template->setIso27701Clauses2019(['7.2.8']);
        self::assertSame($template->getIso27701Clauses2025(), $template->getIso27701Clauses2019());

        // Independent reset: clearing 2025 leaves 2019 intact (used as
        // fallback by PolicySettingProvider::tagDocumentWithIso27701).
        $template->setIso27701Clauses2025(null);
        self::assertNull($template->getIso27701Clauses2025());
        self::assertSame(['7.2.8'], $template->getIso27701Clauses2019());
    }
}
