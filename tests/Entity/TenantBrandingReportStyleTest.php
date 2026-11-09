<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\TenantBranding;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the reportDoc* style fields on TenantBranding
 * (Sprint report-style-admin). Verifies defaults, round-trip
 * and the snapshot helper that feeds the
 * `_fa_report_doc.html.twig` macro.
 */
final class TenantBrandingReportStyleTest extends TestCase
{
    #[Test]
    public function defaultsMatchSpec(): void
    {
        $b = new TenantBranding();

        self::assertTrue($b->isReportDocWatermarkEnabled());
        self::assertTrue($b->isReportDocShowExecSummary());
        self::assertTrue($b->isReportDocShowAppendix());
        self::assertTrue($b->isReportDocShowDistributionList());
        self::assertNull($b->getReportDocFooterDisclaimer());
    }

    #[Test]
    public function roundTripBooleans(): void
    {
        $b = new TenantBranding();
        $b->setReportDocWatermarkEnabled(false)
            ->setReportDocShowExecSummary(false)
            ->setReportDocShowAppendix(false)
            ->setReportDocShowDistributionList(false);

        self::assertFalse($b->isReportDocWatermarkEnabled());
        self::assertFalse($b->isReportDocShowExecSummary());
        self::assertFalse($b->isReportDocShowAppendix());
        self::assertFalse($b->isReportDocShowDistributionList());
    }

    #[Test]
    public function roundTripNullableTextFields(): void
    {
        $b = new TenantBranding();
        $b->setReportDocFooterDisclaimer('Confidential / TLP:RED');

        self::assertSame('Confidential / TLP:RED', $b->getReportDocFooterDisclaimer());

        $b->setReportDocFooterDisclaimer(null);
        self::assertNull($b->getReportDocFooterDisclaimer());
    }

    #[Test]
    public function snapshotHelperReturnsAllKeys(): void
    {
        $b = new TenantBranding();
        $cfg = $b->getReportDocStyleConfig();

        $expectedKeys = [
            'watermark_enabled', 'show_exec_summary', 'show_appendix',
            'show_distribution_list', 'footer_disclaimer',
            'primary_color', 'secondary_color', 'logo_path',
        ];
        foreach ($expectedKeys as $k) {
            self::assertArrayHasKey($k, $cfg, "snapshot is missing key: {$k}");
        }

        self::assertSame($expectedKeys, array_keys($cfg), 'removed style keys must not come back');

        // Defaults flow through.
        self::assertTrue($cfg['watermark_enabled']);
        self::assertSame('#0d6efd', $cfg['primary_color']);
    }

    #[Test]
    public function snapshotReflectsMutations(): void
    {
        $b = new TenantBranding();
        $b->setReportDocWatermarkEnabled(false)
            ->setReportDocFooterDisclaimer('BaFin-Bericht intern');

        $cfg = $b->getReportDocStyleConfig();
        self::assertFalse($cfg['watermark_enabled']);
        self::assertSame('BaFin-Bericht intern', $cfg['footer_disclaimer']);
    }
}
