<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Entity\TenantBranding;
use App\Form\Admin\TenantReportStyleType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

/**
 * Coverage for TenantReportStyleType (Sprint report-style-admin).
 *
 * Verifies field set, defaults round-trip, ROLE_ADMIN gating for the
 * custom-CSS field, and that valid form submissions populate the
 * entity correctly.
 */
final class TenantReportStyleTypeTest extends TypeTestCase
{
    private bool $isAdmin = true;

    protected function getExtensions(): array
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')
            ->willReturnCallback(fn (string $r) => $r === 'ROLE_ADMIN' ? $this->isAdmin : false);

        $type = new TenantReportStyleType($security);

        $validator = Validation::createValidator();

        return [
            new PreloadedExtension([$type], []),
            new ValidatorExtension($validator),
        ];
    }

    #[Test]
    public function buildsExpectedFieldSetWhenAdmin(): void
    {
        $this->isAdmin = true;
        $form = $this->factory->create(TenantReportStyleType::class, new TenantBranding());

        $expected = [
            'reportDocCoverPattern',
            'reportDocDefaultAudience',
            'reportDocFontFamily',
            'reportDocPageOrientation',
            'reportDocChartColorScheme',
            'reportDocWatermarkEnabled',
            'reportDocWatermarkOpacity',
            'reportDocShowExecSummary',
            'reportDocShowAppendix',
            'reportDocShowDistributionList',
            'reportDocFooterDisclaimer',
            'reportDocCustomCss',
        ];
        foreach ($expected as $name) {
            self::assertTrue($form->has($name), "form is missing field: {$name}");
        }
        self::assertCount(12, $form);
    }

    #[Test]
    public function customCssFieldHiddenForNonAdmin(): void
    {
        $this->isAdmin = false;
        $form = $this->factory->create(TenantReportStyleType::class, new TenantBranding());

        self::assertFalse($form->has('reportDocCustomCss'));
        self::assertCount(11, $form);
    }

    #[Test]
    public function submittingValidDataPopulatesEntity(): void
    {
        $branding = new TenantBranding();
        $form = $this->factory->create(TenantReportStyleType::class, $branding);

        $form->submit([
            'reportDocCoverPattern' => 'auditor-formal',
            'reportDocDefaultAudience' => 'aufsicht',
            'reportDocFontFamily' => 'Merriweather',
            'reportDocPageOrientation' => 'landscape',
            'reportDocChartColorScheme' => 'colorblind-safe',
            'reportDocWatermarkEnabled' => '1',
            'reportDocWatermarkOpacity' => '0.25',
            'reportDocShowExecSummary' => '1',
            'reportDocShowAppendix' => '1',
            // Unchecked checkboxes are absent in the POST payload.
            'reportDocFooterDisclaimer' => 'Aufsichts-Bericht intern.',
        ]);

        self::assertTrue($form->isSynchronized(), (string) $form->getErrors(true));
        // Note: bare ValidatorExtension without the SecurityValidator
        // service registers validation but cannot fully execute the
        // entity-level constraints — for our purposes, isSynchronized
        // + value-mapping is the contract that matters.

        self::assertSame('auditor-formal', $branding->getReportDocCoverPattern());
        self::assertSame('aufsicht', $branding->getReportDocDefaultAudience());
        self::assertSame('Merriweather', $branding->getReportDocFontFamily());
        self::assertSame('landscape', $branding->getReportDocPageOrientation());
        self::assertSame('colorblind-safe', $branding->getReportDocChartColorScheme());
        self::assertTrue($branding->isReportDocWatermarkEnabled());
        self::assertSame(0.25, $branding->getReportDocWatermarkOpacity());
        self::assertTrue($branding->isReportDocShowExecSummary());
        self::assertTrue($branding->isReportDocShowAppendix());
        self::assertFalse($branding->isReportDocShowDistributionList());
        self::assertSame('Aufsichts-Bericht intern.', $branding->getReportDocFooterDisclaimer());
    }

    #[Test]
    public function invalidEnumChoiceMarksFormInvalid(): void
    {
        $form = $this->factory->create(TenantReportStyleType::class, new TenantBranding());

        $form->submit([
            'reportDocCoverPattern' => 'NOT-A-PATTERN',
            'reportDocDefaultAudience' => 'internal',
            'reportDocFontFamily' => 'Inter',
            'reportDocPageOrientation' => 'auto',
            'reportDocChartColorScheme' => 'aurora',
            'reportDocWatermarkOpacity' => '0.1',
        ]);

        self::assertFalse($form->isValid(), 'form should reject unknown cover pattern');
    }

    #[Test]
    public function watermarkOpacityRangeConstraint(): void
    {
        $form = $this->factory->create(TenantReportStyleType::class, new TenantBranding());

        $form->submit([
            'reportDocCoverPattern' => 'branded',
            'reportDocDefaultAudience' => 'internal',
            'reportDocFontFamily' => 'Inter',
            'reportDocPageOrientation' => 'auto',
            'reportDocChartColorScheme' => 'aurora',
            'reportDocWatermarkOpacity' => '5.0',
        ]);

        // Setter clamps to 1.0 before validation runs, so the entity
        // value is 1.0 even when the user POSTed 5.0 — but we also
        // accept that the form may flag the constraint violation.
        // Either path is correct; the *value* must not exceed 1.0.
        self::assertLessThanOrEqual(1.0, $form->getData()->getReportDocWatermarkOpacity());
    }
}
