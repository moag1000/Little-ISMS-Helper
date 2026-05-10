<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Entity\TenantBranding;
use App\Form\Admin\TenantPolicyStyleType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Sprint policy-style-admin — exercise the TenantPolicyStyleType form
 * happy-paths, validation, defaults, and ROLE-gated custom-CSS field.
 */
final class TenantPolicyStyleTypeTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var FormFactoryInterface $factory */
        $factory = static::getContainer()->get('form.factory');
        $this->factory = $factory;
    }

    private function newType(bool $admin = true): TenantPolicyStyleType
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn(string $role): bool => $admin && $role === 'ROLE_ADMIN',
        );
        return new TenantPolicyStyleType($security);
    }

    #[Test]
    public function happyPathSubmitsClean(): void
    {
        $type = $this->newType(admin: true);
        $branding = new TenantBranding();

        // Use direct factory create with the constructed type to bypass DI.
        $builder = $this->factory->createBuilder(
            \Symfony\Component\Form\Extension\Core\Type\FormType::class,
            null,
            ['data_class' => TenantBranding::class, 'csrf_protection' => false],
        );
        $type->buildForm($builder, ['data_class' => TenantBranding::class]);
        $form = $builder->getForm();
        $form->setData($branding);

        $form->submit([
            'policyDocFontFamily' => 'Roboto',
            'policyDocCoverPattern' => 'engineering',
            'policyDocCoverLogoSize' => 'large',
            'policyDocPageMargin' => 'wide',
            'policyDocWatermarkEnabled' => '1',
            'policyDocWatermarkOpacity' => '0.42',
            'policyDocSignatureLines' => '5',
            'policyDocShowToc' => '1',
            // policyDocShowHistory intentionally absent → unchecked
            'policyDocShowAnnexARefs' => '1',
            'policyDocFooterText' => 'Internal use',
            'policyDocCustomCss' => '/* tenant override */',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid(), implode(' / ', array_map(
            static fn($e) => $e->getMessage(),
            iterator_to_array($form->getErrors(true)),
        )));

        self::assertSame('Roboto', $branding->getPolicyDocFontFamily());
        self::assertSame('engineering', $branding->getPolicyDocCoverPattern());
        self::assertSame('large', $branding->getPolicyDocCoverLogoSize());
        self::assertSame('wide', $branding->getPolicyDocPageMargin());
        self::assertTrue($branding->isPolicyDocWatermarkEnabled());
        self::assertSame(0.42, $branding->getPolicyDocWatermarkOpacity());
        self::assertSame(5, $branding->getPolicyDocSignatureLines());
        self::assertTrue($branding->isPolicyDocShowToc());
        self::assertFalse($branding->isPolicyDocShowHistory());
        self::assertTrue($branding->isPolicyDocShowAnnexARefs());
        self::assertSame('Internal use', $branding->getPolicyDocFooterText());
        self::assertSame('/* tenant override */', $branding->getPolicyDocCustomCss());
    }

    #[Test]
    public function watermarkOpacityRangeValidatorRejectsOutOfRange(): void
    {
        $type = $this->newType();
        $branding = new TenantBranding();
        $builder = $this->factory->createBuilder(
            \Symfony\Component\Form\Extension\Core\Type\FormType::class,
            null,
            ['data_class' => TenantBranding::class, 'csrf_protection' => false],
        );
        $type->buildForm($builder, ['data_class' => TenantBranding::class]);
        $form = $builder->getForm();
        $form->setData($branding);

        $form->submit([
            'policyDocFontFamily' => 'Inter',
            'policyDocCoverPattern' => 'branded',
            'policyDocCoverLogoSize' => 'medium',
            'policyDocPageMargin' => 'standard',
            'policyDocWatermarkOpacity' => '1.42',
            'policyDocSignatureLines' => '3',
        ]);

        // Submitted but invalid: range constraint violated.
        self::assertTrue($form->isSubmitted());
        self::assertFalse($form->isValid());
    }

    #[Test]
    public function signatureLinesValidatorRejectsZero(): void
    {
        $type = $this->newType();
        $branding = new TenantBranding();
        $builder = $this->factory->createBuilder(
            \Symfony\Component\Form\Extension\Core\Type\FormType::class,
            null,
            ['data_class' => TenantBranding::class, 'csrf_protection' => false],
        );
        $type->buildForm($builder, ['data_class' => TenantBranding::class]);
        $form = $builder->getForm();
        $form->setData($branding);

        $form->submit([
            'policyDocFontFamily' => 'Inter',
            'policyDocCoverPattern' => 'branded',
            'policyDocCoverLogoSize' => 'medium',
            'policyDocPageMargin' => 'standard',
            'policyDocWatermarkOpacity' => '0.08',
            'policyDocSignatureLines' => '0',
        ]);

        self::assertFalse($form->isValid());
    }

    #[Test]
    public function customCssFieldHiddenForNonAdmin(): void
    {
        $type = $this->newType(admin: false);
        $builder = $this->factory->createBuilder(
            \Symfony\Component\Form\Extension\Core\Type\FormType::class,
            null,
            ['data_class' => TenantBranding::class, 'csrf_protection' => false],
        );
        $type->buildForm($builder, ['data_class' => TenantBranding::class]);
        $form = $builder->getForm();

        self::assertFalse($form->has('policyDocCustomCss'),
            'Custom-CSS textarea must be ROLE_ADMIN-gated.');
    }

    #[Test]
    public function customCssFieldVisibleForAdmin(): void
    {
        $type = $this->newType(admin: true);
        $builder = $this->factory->createBuilder(
            \Symfony\Component\Form\Extension\Core\Type\FormType::class,
            null,
            ['data_class' => TenantBranding::class, 'csrf_protection' => false],
        );
        $type->buildForm($builder, ['data_class' => TenantBranding::class]);
        $form = $builder->getForm();

        self::assertTrue($form->has('policyDocCustomCss'));
    }
}
