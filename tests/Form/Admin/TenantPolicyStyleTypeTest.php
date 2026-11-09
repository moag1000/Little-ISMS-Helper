<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Entity\TenantBranding;
use App\Form\Admin\TenantPolicyStyleType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Sprint policy-style-admin — exercise the TenantPolicyStyleType form
 * happy-paths, validation and defaults.
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

    private function newType(): TenantPolicyStyleType
    {
        return new TenantPolicyStyleType();
    }

    #[Test]
    public function happyPathSubmitsClean(): void
    {
        $type = $this->newType();
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
            'policyDocCoverLogoSize' => 'large',
            'policyDocPageMargin' => 'wide',
            'policyDocWatermarkEnabled' => '1',
            'policyDocWatermarkOpacity' => '0.42',
            'policyDocSignatureLines' => '5',
            'policyDocShowToc' => '1',
            // policyDocShowHistory intentionally absent → unchecked
            'policyDocShowAnnexARefs' => '1',
            'policyDocFooterText' => 'Internal use',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid(), implode(' / ', array_map(
            static fn($e) => $e->getMessage(),
            iterator_to_array($form->getErrors(true)),
        )));

        self::assertSame('large', $branding->getPolicyDocCoverLogoSize());
        self::assertSame('wide', $branding->getPolicyDocPageMargin());
        self::assertTrue($branding->isPolicyDocWatermarkEnabled());
        self::assertSame(0.42, $branding->getPolicyDocWatermarkOpacity());
        self::assertSame(5, $branding->getPolicyDocSignatureLines());
        self::assertTrue($branding->isPolicyDocShowToc());
        self::assertFalse($branding->isPolicyDocShowHistory());
        self::assertTrue($branding->isPolicyDocShowAnnexARefs());
        self::assertSame('Internal use', $branding->getPolicyDocFooterText());
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
            'policyDocCoverLogoSize' => 'medium',
            'policyDocPageMargin' => 'standard',
            'policyDocWatermarkOpacity' => '0.08',
            'policyDocSignatureLines' => '0',
        ]);

        self::assertFalse($form->isValid());
    }

    #[Test]
    public function removedFieldsAreGone(): void
    {
        $builder = $this->factory->createBuilder(
            \Symfony\Component\Form\Extension\Core\Type\FormType::class,
            null,
            ['data_class' => TenantBranding::class, 'csrf_protection' => false],
        );
        $this->newType()->buildForm($builder, ['data_class' => TenantBranding::class]);
        $form = $builder->getForm();

        foreach (['policyDocFontFamily', 'policyDocCoverPattern', 'policyDocCustomCss'] as $name) {
            self::assertFalse($form->has($name), "{$name} was removed (stored but never read).");
        }
    }
}
