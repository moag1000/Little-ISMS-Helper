<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\TenantBranding;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the 12 reportDoc* style fields on TenantBranding
 * (Sprint report-style-admin). Verifies defaults, round-trip,
 * enum guards, and the snapshot helper that feeds the
 * `_fa_report_doc.html.twig` macro.
 */
final class TenantBrandingReportStyleTest extends TestCase
{
    #[Test]
    public function defaultsMatchSpec(): void
    {
        $b = new TenantBranding();

        self::assertSame('branded', $b->getReportDocCoverPattern());
        self::assertSame('internal', $b->getReportDocDefaultAudience());
        self::assertTrue($b->isReportDocWatermarkEnabled());
        self::assertSame(0.08, $b->getReportDocWatermarkOpacity());
        self::assertTrue($b->isReportDocShowExecSummary());
        self::assertTrue($b->isReportDocShowAppendix());
        self::assertTrue($b->isReportDocShowDistributionList());
        self::assertSame('Inter', $b->getReportDocFontFamily());
        self::assertSame('auto', $b->getReportDocPageOrientation());
        self::assertSame('aurora', $b->getReportDocChartColorScheme());
        self::assertNull($b->getReportDocFooterDisclaimer());
        self::assertNull($b->getReportDocCustomCss());
    }

    #[Test]
    public function roundTripStrings(): void
    {
        $b = new TenantBranding();
        $b->setReportDocCoverPattern('auditor-formal')
            ->setReportDocDefaultAudience('vorstand')
            ->setReportDocFontFamily('Merriweather')
            ->setReportDocPageOrientation('landscape')
            ->setReportDocChartColorScheme('colorblind-safe');

        self::assertSame('auditor-formal', $b->getReportDocCoverPattern());
        self::assertSame('vorstand', $b->getReportDocDefaultAudience());
        self::assertSame('Merriweather', $b->getReportDocFontFamily());
        self::assertSame('landscape', $b->getReportDocPageOrientation());
        self::assertSame('colorblind-safe', $b->getReportDocChartColorScheme());
    }

    #[Test]
    public function roundTripBooleansAndOpacity(): void
    {
        $b = new TenantBranding();
        $b->setReportDocWatermarkEnabled(false)
            ->setReportDocWatermarkOpacity(0.42)
            ->setReportDocShowExecSummary(false)
            ->setReportDocShowAppendix(false)
            ->setReportDocShowDistributionList(false);

        self::assertFalse($b->isReportDocWatermarkEnabled());
        self::assertSame(0.42, $b->getReportDocWatermarkOpacity());
        self::assertFalse($b->isReportDocShowExecSummary());
        self::assertFalse($b->isReportDocShowAppendix());
        self::assertFalse($b->isReportDocShowDistributionList());
    }

    #[Test]
    public function roundTripNullableTextFields(): void
    {
        $b = new TenantBranding();
        $b->setReportDocFooterDisclaimer('Confidential / TLP:RED')
            ->setReportDocCustomCss('.report-doc-preview__title { color: red; }');

        self::assertSame('Confidential / TLP:RED', $b->getReportDocFooterDisclaimer());
        self::assertSame('.report-doc-preview__title { color: red; }', $b->getReportDocCustomCss());

        $b->setReportDocFooterDisclaimer(null)->setReportDocCustomCss(null);
        self::assertNull($b->getReportDocFooterDisclaimer());
        self::assertNull($b->getReportDocCustomCss());
    }

    #[Test]
    public function watermarkOpacityClampsBelowZero(): void
    {
        $b = new TenantBranding();
        $b->setReportDocWatermarkOpacity(-0.5);
        self::assertSame(0.0, $b->getReportDocWatermarkOpacity());
    }

    #[Test]
    public function watermarkOpacityClampsAboveOne(): void
    {
        $b = new TenantBranding();
        $b->setReportDocWatermarkOpacity(1.7);
        self::assertSame(1.0, $b->getReportDocWatermarkOpacity());
    }

    #[Test]
    public function coverPatternRejectsUnknown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TenantBranding())->setReportDocCoverPattern('crazy-cover');
    }

    #[Test]
    public function audienceRejectsUnknown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TenantBranding())->setReportDocDefaultAudience('president');
    }

    #[Test]
    public function pageOrientationRejectsUnknown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TenantBranding())->setReportDocPageOrientation('diagonal');
    }

    #[Test]
    public function chartColorSchemeRejectsUnknown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TenantBranding())->setReportDocChartColorScheme('rainbow');
    }

    #[Test]
    public function snapshotHelperReturnsAllKeys(): void
    {
        $b = new TenantBranding();
        $cfg = $b->getReportDocStyleConfig();

        $expectedKeys = [
            'cover_pattern', 'default_audience', 'watermark_enabled',
            'watermark_opacity', 'show_exec_summary', 'show_appendix',
            'show_distribution_list', 'font_family', 'page_orientation',
            'chart_color_scheme', 'footer_disclaimer', 'custom_css',
            'primary_color', 'secondary_color', 'logo_path',
        ];
        foreach ($expectedKeys as $k) {
            self::assertArrayHasKey($k, $cfg, "snapshot is missing key: {$k}");
        }

        // Defaults flow through.
        self::assertSame('branded', $cfg['cover_pattern']);
        self::assertSame('internal', $cfg['default_audience']);
        self::assertSame('aurora', $cfg['chart_color_scheme']);
        self::assertSame('#0d6efd', $cfg['primary_color']);
    }

    #[Test]
    public function snapshotReflectsMutations(): void
    {
        $b = new TenantBranding();
        $b->setReportDocCoverPattern('board-formal')
            ->setReportDocDefaultAudience('aufsicht')
            ->setReportDocChartColorScheme('audit')
            ->setReportDocWatermarkEnabled(false)
            ->setReportDocFooterDisclaimer('BaFin-Bericht intern');

        $cfg = $b->getReportDocStyleConfig();
        self::assertSame('board-formal', $cfg['cover_pattern']);
        self::assertSame('aufsicht', $cfg['default_audience']);
        self::assertSame('audit', $cfg['chart_color_scheme']);
        self::assertFalse($cfg['watermark_enabled']);
        self::assertSame('BaFin-Bericht intern', $cfg['footer_disclaimer']);
    }
}
