<?php

declare(strict_types=1);

namespace App\Tests\Form\Trait;

use App\Entity\User;
use App\Form\Trait\OwnerPickerFormTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\User\UserInterface;

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
 *  - default_to_current_user pre-fills new entities (Junior-ISB-Audit 4.11)
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

    #[Test]
    public function defaultCommonPrefixPinsMessagesDomainSoCommonKeysResolve(): void
    {
        // prefix='common' → common.* keys live in `messages`; without pinning,
        // a privacy/training/bcm form would look them up in its own domain and
        // render raw `common.field.owner_user` strings.
        $form = $this->formFactory->create(OwnerPickerTraitFixtureType_Minimal::class);

        self::assertSame('messages', $form->get('ownerUser')->getConfig()->getOption('translation_domain'));
        self::assertSame('messages', $form->get('ownerPerson')->getConfig()->getOption('translation_domain'));
    }

    #[Test]
    public function customPrefixInheritsHostFormDomain(): void
    {
        // prefix='asset' → keys live in the host form's own domain; the trait
        // must NOT pin `messages` (that would break asset/risk/... forms). null
        // = inherit from parent form (existing, working behaviour).
        $form = $this->formFactory->create(OwnerPickerTraitFixtureType_Full::class);

        self::assertNull($form->get('ownerUser')->getConfig()->getOption('translation_domain'));
    }

    /**
     * Junior-ISB-Audit-2026-05-22 4.11: Owner pre-fill — UX-Polish.
     *
     * Verifies the PRE_SET_DATA listener attached by the trait fires on a
     * NEW entity and pre-fills the User-slot with the currently
     * authenticated user. We intentionally bypass the EntityType
     * choice-validation (which would otherwise reject a transient User
     * not persisted via the test EntityManager) by inspecting the entity
     * BEFORE it reaches the EntityType normalizer — i.e. via a higher-
     * priority listener that captures the entity state right after the
     * trait's listener has run.
     */
    #[Test]
    public function defaultToCurrentUserPreFillsNewEntity(): void
    {
        $currentUser = new User();
        $entity = new OwnerPickerPreFillFixtureEntity();
        self::assertNull($entity->getOwnerUser(), 'pre-condition: entity starts with no owner');

        OwnerPickerTraitFixtureType_DefaultUser::$injectedUser = $currentUser;
        $observed = $this->buildFormAndCaptureEntity(
            OwnerPickerTraitFixtureType_DefaultUser::class,
            $entity,
        );

        self::assertSame(
            $currentUser,
            $observed['ownerUser'],
            'new entity must be pre-filled with the current authenticated user',
        );
    }

    /**
     * Existing owner must never be overwritten — only NEW entities get the
     * pre-fill. Critical to avoid silently re-assigning persisted ownership.
     */
    #[Test]
    public function defaultToCurrentUserDoesNotOverwriteExistingOwner(): void
    {
        $existingOwner = new User();
        $currentUser = new User();
        $entity = new OwnerPickerPreFillFixtureEntity();
        $entity->setOwnerUser($existingOwner);

        OwnerPickerTraitFixtureType_DefaultUser::$injectedUser = $currentUser;
        $observed = $this->buildFormAndCaptureEntity(
            OwnerPickerTraitFixtureType_DefaultUser::class,
            $entity,
        );

        self::assertSame(
            $existingOwner,
            $observed['ownerUser'],
            'existing owner must survive untouched',
        );
    }

    /**
     * Opt-out (default) must NOT touch the entity — backward-compat guard.
     */
    #[Test]
    public function withoutOptInPreFillIsSkipped(): void
    {
        $currentUser = new User();
        $entity = new OwnerPickerPreFillFixtureEntity();

        OwnerPickerTraitFixtureType_NoDefaultUser::$injectedUser = $currentUser;
        $observed = $this->buildFormAndCaptureEntity(
            OwnerPickerTraitFixtureType_NoDefaultUser::class,
            $entity,
        );

        self::assertNull(
            $observed['ownerUser'],
            'without opt-in, no pre-fill must happen (backward-compat)',
        );
    }

    /**
     * Builds a form via the FormFactory, captures the entity's owner-user
     * value right after the trait's PRE_SET_DATA listener has fired, and
     * returns it before downstream EntityType validation runs (which
     * would reject transient Users with "must be managed" errors).
     *
     * @return array{ownerUser: ?User}
     */
    private function buildFormAndCaptureEntity(string $formTypeClass, OwnerPickerPreFillFixtureEntity $entity): array
    {
        $captured = ['ownerUser' => null];
        $builder = $this->formFactory->createBuilder($formTypeClass, $entity);
        // Attach a lower-priority listener so it runs AFTER the trait's
        // listener (which uses default priority 0). PRE_SET_DATA listeners
        // are invoked in descending priority order; we capture last.
        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            static function (FormEvent $event) use (&$captured): void {
                $data = $event->getData();
                if ($data instanceof OwnerPickerPreFillFixtureEntity) {
                    $captured['ownerUser'] = $data->getOwnerUser();
                }
            },
            -100,
        );

        try {
            $builder->getForm();
        } catch (\Throwable) {
            // EntityType choice-loader will throw if the User is transient.
            // The listener has already captured what we need, so swallow.
        }

        return $captured;
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

/**
 * Stand-in entity for pre-fill tests. Mirrors the Asset/Risk/BP/Incident
 * `ownerUser` slot accessor contract without dragging in the full Doctrine
 * mapping.
 */
class OwnerPickerPreFillFixtureEntity
{
    private ?User $ownerUser = null;
    private mixed $ownerPerson = null;
    private mixed $owner = null;

    public function getOwnerUser(): ?User
    {
        return $this->ownerUser;
    }
    public function setOwnerUser(?User $ownerUser): static
    {
        $this->ownerUser = $ownerUser;
        return $this;
    }
    public function getOwnerPerson(): mixed
    {
        return $this->ownerPerson;
    }
    public function setOwnerPerson(mixed $ownerPerson): static
    {
        $this->ownerPerson = $ownerPerson;
        return $this;
    }
    public function getOwner(): mixed
    {
        return $this->owner;
    }
    public function setOwner(mixed $owner): static
    {
        $this->owner = $owner;
        return $this;
    }
}

/**
 * Fixture FormType with `default_to_current_user` opt-in.
 * Uses a stubbed Security service that returns a pre-set User instance.
 */
class OwnerPickerTraitFixtureType_DefaultUser extends AbstractType
{
    use OwnerPickerFormTrait;

    public static ?User $injectedUser = null;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addOwnerPicker($builder, [
            'user_field'              => 'ownerUser',
            'person_field'            => 'ownerPerson',
            'translation_prefix'      => 'asset',
            'default_to_current_user' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OwnerPickerPreFillFixtureEntity::class]);
    }

    protected function getSecurityForOwnerPicker(): ?Security
    {
        if (self::$injectedUser === null) {
            return null;
        }
        $user = self::$injectedUser;
        return new class($user) extends Security {
            public function __construct(private readonly User $injectedUser)
            {
                // Skip parent constructor — we override only getUser() so the
                // rest of the Security surface stays untouched for the
                // pre-fill use-case.
            }

            public function getUser(): ?UserInterface
            {
                return $this->injectedUser;
            }
        };
    }
}

/**
 * Fixture FormType WITHOUT the opt-in — backward-compat baseline.
 */
class OwnerPickerTraitFixtureType_NoDefaultUser extends AbstractType
{
    use OwnerPickerFormTrait;

    public static ?User $injectedUser = null;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addOwnerPicker($builder, [
            'user_field'         => 'ownerUser',
            'person_field'       => 'ownerPerson',
            'translation_prefix' => 'asset',
            // default_to_current_user intentionally omitted (false)
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OwnerPickerPreFillFixtureEntity::class]);
    }
}
