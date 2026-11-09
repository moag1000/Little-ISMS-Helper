<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Entity\TenantBranding;
use App\Form\Admin\TenantReportStyleType;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

/**
 * Coverage for TenantReportStyleType (Sprint report-style-admin).
 *
 * Verifies the field set and that valid form submissions populate the
 * entity correctly.
 */
#[AllowMockObjectsWithoutExpectations] // TypeTestCase mocks the event dispatcher itself
final class TenantReportStyleTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        $validator = Validation::createValidator();

        return [
            new PreloadedExtension([new TenantReportStyleType()], []),
            new ValidatorExtension($validator),
        ];
    }

    #[Test]
    public function buildsExpectedFieldSet(): void
    {
        $form = $this->factory->create(TenantReportStyleType::class, new TenantBranding());

        $expected = [
            'reportDocWatermarkEnabled',
            'reportDocShowExecSummary',
            'reportDocShowAppendix',
            'reportDocShowDistributionList',
            'reportDocFooterDisclaimer',
        ];
        foreach ($expected as $name) {
            self::assertTrue($form->has($name), "form is missing field: {$name}");
        }
        self::assertCount(5, $form);
    }

    #[Test]
    public function submittingValidDataPopulatesEntity(): void
    {
        $branding = new TenantBranding();
        $form = $this->factory->create(TenantReportStyleType::class, $branding);

        $form->submit([
            'reportDocWatermarkEnabled' => '1',
            'reportDocShowExecSummary' => '1',
            'reportDocShowAppendix' => '1',
            // Unchecked checkboxes are absent in the POST payload.
            'reportDocFooterDisclaimer' => 'Aufsichts-Bericht intern.',
        ]);

        self::assertTrue($form->isSynchronized(), (string) $form->getErrors(true));

        self::assertTrue($branding->isReportDocWatermarkEnabled());
        self::assertTrue($branding->isReportDocShowExecSummary());
        self::assertTrue($branding->isReportDocShowAppendix());
        self::assertFalse($branding->isReportDocShowDistributionList());
        self::assertSame('Aufsichts-Bericht intern.', $branding->getReportDocFooterDisclaimer());
    }

    #[Test]
    public function footerDisclaimerLengthConstraint(): void
    {
        $form = $this->factory->create(TenantReportStyleType::class, new TenantBranding());

        $form->submit([
            'reportDocFooterDisclaimer' => str_repeat('x', 1001),
        ]);

        self::assertFalse($form->isValid(), 'a disclaimer longer than 1000 characters must be rejected');
    }
}
