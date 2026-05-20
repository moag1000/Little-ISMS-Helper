<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\TenantBranding;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sprint policy-style-admin — coverage of the 12 `policyDoc*`
 * accessors on TenantBranding plus the style-config snapshot helper.
 */
final class TenantBrandingPolicyStyleTest extends TestCase
{
    #[Test]
    public function defaultsAreSensible(): void
    {
        $b = new TenantBranding();

        self::assertSame('Inter', $b->getPolicyDocFontFamily());
        self::assertSame('branded', $b->getPolicyDocCoverPattern());
        self::assertTrue($b->isPolicyDocWatermarkEnabled());
        self::assertSame(0.08, $b->getPolicyDocWatermarkOpacity());
        self::assertSame(3, $b->getPolicyDocSignatureLines());
        self::assertTrue($b->isPolicyDocShowToc());
        self::assertTrue($b->isPolicyDocShowHistory());
        self::assertTrue($b->isPolicyDocShowAnnexARefs());
        self::assertNull($b->getPolicyDocFooterText());
        self::assertSame('medium', $b->getPolicyDocCoverLogoSize());
        self::assertSame('standard', $b->getPolicyDocPageMargin());
        self::assertNull($b->getPolicyDocCustomCss());
    }

    #[Test]
    public function settersRoundTrip(): void
    {
        $b = (new TenantBranding())
            ->setPolicyDocFontFamily('Roboto')
            ->setPolicyDocCoverPattern('auditor-formal')
            ->setPolicyDocWatermarkEnabled(false)
            ->setPolicyDocWatermarkOpacity(0.42)
            ->setPolicyDocSignatureLines(5)
            ->setPolicyDocShowToc(false)
            ->setPolicyDocShowHistory(false)
            ->setPolicyDocShowAnnexARefs(false)
            ->setPolicyDocFooterText('Confidential — internal use only')
            ->setPolicyDocCoverLogoSize('large')
            ->setPolicyDocPageMargin('wide')
            ->setPolicyDocCustomCss('.policy-doc-preview { background: #fff5e6; }');

        self::assertSame('Roboto', $b->getPolicyDocFontFamily());
        self::assertSame('auditor-formal', $b->getPolicyDocCoverPattern());
        self::assertFalse($b->isPolicyDocWatermarkEnabled());
        self::assertSame(0.42, $b->getPolicyDocWatermarkOpacity());
        self::assertSame(5, $b->getPolicyDocSignatureLines());
        self::assertFalse($b->isPolicyDocShowToc());
        self::assertFalse($b->isPolicyDocShowHistory());
        self::assertFalse($b->isPolicyDocShowAnnexARefs());
        self::assertSame('Confidential — internal use only', $b->getPolicyDocFooterText());
        self::assertSame('large', $b->getPolicyDocCoverLogoSize());
        self::assertSame('wide', $b->getPolicyDocPageMargin());
        self::assertSame('.policy-doc-preview { background: #fff5e6; }', $b->getPolicyDocCustomCss());
    }

    #[Test]
    public function invalidCoverPatternRejected(): void
    {
        $this->expectException(\App\Exception\InvalidArgument\InvalidArgumentException::class);
        (new TenantBranding())->setPolicyDocCoverPattern('disco-funk');
    }

    #[Test]
    public function invalidLogoSizeRejected(): void
    {
        $this->expectException(\App\Exception\InvalidArgument\InvalidArgumentException::class);
        (new TenantBranding())->setPolicyDocCoverLogoSize('xxl');
    }

    #[Test]
    public function invalidPageMarginRejected(): void
    {
        $this->expectException(\App\Exception\InvalidArgument\InvalidArgumentException::class);
        (new TenantBranding())->setPolicyDocPageMargin('square');
    }

    #[Test]
    public function watermarkOpacityClampedAboveOne(): void
    {
        // Setter clamps for Symfony-Form-binding compatibility; the
        // form-level Range constraint catches the user-error first.
        $b = (new TenantBranding())->setPolicyDocWatermarkOpacity(1.5);
        self::assertSame(1.0, $b->getPolicyDocWatermarkOpacity());
    }

    #[Test]
    public function watermarkOpacityClampedBelowZero(): void
    {
        $b = (new TenantBranding())->setPolicyDocWatermarkOpacity(-0.1);
        self::assertSame(0.0, $b->getPolicyDocWatermarkOpacity());
    }

    #[Test]
    public function signatureLinesClampedAboveSix(): void
    {
        $b = (new TenantBranding())->setPolicyDocSignatureLines(7);
        self::assertSame(6, $b->getPolicyDocSignatureLines());
    }

    #[Test]
    public function signatureLinesClampedBelowOne(): void
    {
        $b = (new TenantBranding())->setPolicyDocSignatureLines(0);
        self::assertSame(1, $b->getPolicyDocSignatureLines());
    }

    #[Test]
    public function styleConfigSnapshotContainsAllKeys(): void
    {
        $b = (new TenantBranding())
            ->setPrimaryColor('#ff5722')
            ->setSecondaryColor('#212121')
            ->setLogoPath('/var/uploads/tenant-1/logo.png')
            ->setPolicyDocFontFamily('Lato')
            ->setPolicyDocCoverPattern('engineering')
            ->setPolicyDocWatermarkOpacity(0.16)
            ->setPolicyDocSignatureLines(2)
            ->setPolicyDocShowToc(false)
            ->setPolicyDocFooterText('CISO-Office');

        $cfg = $b->getPolicyDocStyleConfig();

        self::assertSame('Lato', $cfg['font_family']);
        self::assertSame('engineering', $cfg['cover_pattern']);
        self::assertTrue($cfg['watermark_enabled']);
        self::assertSame(0.16, $cfg['watermark_opacity']);
        self::assertSame(2, $cfg['signature_lines']);
        self::assertFalse($cfg['show_toc']);
        self::assertTrue($cfg['show_history']);
        self::assertTrue($cfg['show_annex_a_refs']);
        self::assertSame('CISO-Office', $cfg['footer_text']);
        self::assertSame('medium', $cfg['cover_logo_size']);
        self::assertSame('standard', $cfg['page_margin']);
        self::assertNull($cfg['custom_css']);
        self::assertSame('#ff5722', $cfg['primary_color']);
        self::assertSame('#212121', $cfg['secondary_color']);
        self::assertSame('/var/uploads/tenant-1/logo.png', $cfg['logo_path']);
    }

    #[Test]
    public function nullableFooterTextResetsCleanly(): void
    {
        $b = (new TenantBranding())->setPolicyDocFooterText('something');
        self::assertSame('something', $b->getPolicyDocFooterText());

        $b->setPolicyDocFooterText(null);
        self::assertNull($b->getPolicyDocFooterText());
    }

    #[Test]
    public function constantsExposeEnumChoices(): void
    {
        self::assertContains('branded', TenantBranding::POLICY_DOC_COVER_PATTERNS);
        self::assertContains('engineering', TenantBranding::POLICY_DOC_COVER_PATTERNS);
        self::assertCount(4, TenantBranding::POLICY_DOC_COVER_PATTERNS);

        self::assertSame(['small', 'medium', 'large'], TenantBranding::POLICY_DOC_LOGO_SIZES);
        self::assertSame(['compact', 'standard', 'wide'], TenantBranding::POLICY_DOC_PAGE_MARGINS);
    }
}
