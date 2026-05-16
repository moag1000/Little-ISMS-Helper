<?php

declare(strict_types=1);

namespace App\Tests\Form\Trait;

use App\Form\Trait\OwnerPickerFormTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Unit-tests for OwnerPickerFormTrait (audit-s4 P-1).
 *
 * Verifies the helper:
 *  - always adds user + person child-fields
 *  - adds deputies + legacy only when configured
 *  - wires the cross-disable Stimulus controller (data-controller attr)
 *  - propagates configurable property-paths so divergent entity layouts
 *    (Risk uses riskOwner, Asset uses ownerUser) work without a schema
 *    change.
 *
 * Uses KernelTestCase so the Doctrine-Bridge Form-Extension is wired by
 * the real container (the trait uses EntityType, which needs Doctrine
 * ManagerRegistry — TypeTestCase alone is too lean for this).
 */
final class OwnerPickerFormTraitTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->formFactory = static::getContainer()->get(FormFactoryInterface::class);
    }

    #[Test]
    public function fullConfigAddsAllFourChildren(): void
    {
        $form = $this->formFactory->create(OwnerPickerTraitFixtureType_Full::class);

        self::assertTrue($form->has('ownerUser'), 'ownerUser child must be present');
        self::assertTrue($form->has('ownerPerson'), 'ownerPerson child must be present');
        self::assertTrue($form->has('ownerDeputyPersons'), 'deputies child must be present when configured');
        self::assertTrue($form->has('owner'), 'legacy child must be present when configured');
    }

    #[Test]
    public function minimalConfigOmitsDeputiesAndLegacy(): void
    {
        $form = $this->formFactory->create(OwnerPickerTraitFixtureType_Minimal::class);

        self::assertTrue($form->has('ownerUser'));
        self::assertTrue($form->has('ownerPerson'));
        self::assertFalse($form->has('ownerDeputyPersons'), 'deputies must be absent without config');
        self::assertFalse($form->has('owner'), 'legacy must be absent without config');
    }

    #[Test]
    public function customFieldNamesAreHonored(): void
    {
        $form = $this->formFactory->create(OwnerPickerTraitFixtureType_Custom::class);

        // Risk-style property names — verifies the trait does not hard-code
        // "ownerUser" / "ownerPerson".
        self::assertTrue($form->has('riskOwner'));
        self::assertTrue($form->has('riskOwnerPerson'));
        self::assertTrue($form->has('riskOwnerDeputyPersons'));
        self::assertFalse($form->has('owner'));
    }

    #[Test]
    public function userFieldExposesOwnerPickerStimulusController(): void
    {
        $form = $this->formFactory->create(OwnerPickerTraitFixtureType_Full::class, null, [
            'csrf_protection' => false,
        ]);

        // Read attrs from FormConfig — avoids createView() session-CSRF dependency.
        $userAttrs = $form->get('ownerUser')->getConfig()->getOption('attr');
        self::assertArrayHasKey('data-controller', $userAttrs);
        self::assertStringContainsString(
            'owner-picker',
            (string) $userAttrs['data-controller'],
            'user select must wire the owner-picker Stimulus controller for cross-disable UX',
        );
        self::assertSame('user', $userAttrs['data-owner-picker-target'] ?? null);
    }

    #[Test]
    public function personFieldHasTargetAndAction(): void
    {
        $form = $this->formFactory->create(OwnerPickerTraitFixtureType_Full::class, null, [
            'csrf_protection' => false,
        ]);

        $personAttrs = $form->get('ownerPerson')->getConfig()->getOption('attr');
        self::assertSame('person', $personAttrs['data-owner-picker-target'] ?? null);
        self::assertStringContainsString('change->owner-picker#toggle', (string) ($personAttrs['data-action'] ?? ''));
    }

    #[Test]
    public function deputiesFieldIsMultiple(): void
    {
        $form = $this->formFactory->create(OwnerPickerTraitFixtureType_Full::class);

        $deputiesConfig = $form->get('ownerDeputyPersons')->getConfig();
        self::assertTrue(
            (bool) $deputiesConfig->getOption('multiple'),
            'deputies field must be a multi-select',
        );
    }
}

/**
 * Fixture FormType: full config (user + person + deputies + legacy).
 */
class OwnerPickerTraitFixtureType_Full extends AbstractType
{
    use OwnerPickerFormTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addOwnerPicker($builder, [
            'user_field'         => 'ownerUser',
            'person_field'       => 'ownerPerson',
            'deputies_field'     => 'ownerDeputyPersons',
            'legacy_field'       => 'owner',
            'translation_prefix' => 'asset',
        ]);
    }
}

/**
 * Fixture FormType: minimal (no deputies, no legacy).
 */
class OwnerPickerTraitFixtureType_Minimal extends AbstractType
{
    use OwnerPickerFormTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addOwnerPicker($builder, [
            'user_field'         => 'ownerUser',
            'person_field'       => 'ownerPerson',
            'translation_prefix' => 'common',
        ]);
    }
}

/**
 * Fixture FormType: Risk-style custom property names.
 */
class OwnerPickerTraitFixtureType_Custom extends AbstractType
{
    use OwnerPickerFormTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addOwnerPicker($builder, [
            'user_field'         => 'riskOwner',
            'person_field'       => 'riskOwnerPerson',
            'deputies_field'     => 'riskOwnerDeputyPersons',
            'legacy_field'       => null,
            'translation_prefix' => 'risk',
            'user_label'         => 'risk.field.risk_owner',
        ]);
    }
}
