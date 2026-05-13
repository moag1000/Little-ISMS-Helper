<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Role;
use App\Entity\User;
use App\Form\UserType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Verifies that UserType in edit-mode pre-fills boolean checkboxes
 * (isActive, isVerified) and role choices from the bound entity,
 * rather than resetting them to null/unchecked.
 *
 * Root-cause guard: setting 'data' => null on a mapped field is an
 * EXPLICIT override — Symfony does NOT fall back to the entity property.
 * The fix omits 'data' entirely in edit mode for isActive and roles.
 */
final class UserTypeEditPrefillTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->formFactory = $container->get(FormFactoryInterface::class);
        $this->em = $container->get(EntityManagerInterface::class);
    }

    #[Test]
    public function editModePreservesIsActiveTrue(): void
    {
        $user = new User();
        $user->setIsActive(true);
        $user->setIsVerified(true);
        $user->setRoles(['ROLE_MANAGER', 'ROLE_DPO']);

        $form = $this->formFactory->create(UserType::class, $user, ['is_edit' => true]);

        self::assertTrue(
            $form->get('isActive')->getData(),
            'isActive must read true from entity in edit mode, not be overridden by null',
        );
    }

    #[Test]
    public function editModePreservesIsActiveFalse(): void
    {
        $user = new User();
        $user->setIsActive(false);
        $user->setIsVerified(false);
        $user->setRoles([]);

        $form = $this->formFactory->create(UserType::class, $user, ['is_edit' => true]);

        self::assertFalse(
            $form->get('isActive')->getData(),
            'isActive must read false from entity in edit mode',
        );
    }

    #[Test]
    public function editModePreservesIsVerifiedTrue(): void
    {
        $user = new User();
        $user->setIsActive(true);
        $user->setIsVerified(true);
        $user->setRoles(['ROLE_MANAGER']);

        $form = $this->formFactory->create(UserType::class, $user, ['is_edit' => true]);

        self::assertTrue(
            $form->get('isVerified')->getData(),
            'isVerified must read true from entity in edit mode',
        );
    }

    #[Test]
    public function editModePreservesRoles(): void
    {
        $user = new User();
        $user->setIsActive(true);
        $user->setRoles(['ROLE_MANAGER', 'ROLE_DPO']);

        $form = $this->formFactory->create(UserType::class, $user, ['is_edit' => true]);

        $rolesData = $form->get('roles')->getData();
        self::assertContains('ROLE_MANAGER', $rolesData, 'ROLE_MANAGER must be pre-selected in edit mode');
        self::assertContains('ROLE_DPO', $rolesData, 'ROLE_DPO must be pre-selected in edit mode');
    }

    #[Test]
    public function editModePreservesCustomRoles(): void
    {
        // Use an existing persisted Role if available, otherwise skip the
        // collection assertion (EntityType needs a managed entity).
        $role = $this->em->getRepository(Role::class)->findOneBy([]);
        if ($role === null) {
            self::markTestSkipped('No Role entities available in test database — customRoles assertion skipped.');
        }

        $user = new User();
        $user->setIsActive(true);
        $user->setRoles([]);
        $user->addCustomRole($role);

        $form = $this->formFactory->create(UserType::class, $user, ['is_edit' => true]);

        self::assertTrue(
            $form->get('customRoles')->getData()->contains($role),
            'customRoles Collection must be pre-selected from entity in edit mode',
        );
    }

    #[Test]
    public function createModeDefaultsToRoleUserAndIsActiveTrue(): void
    {
        $user = new User();

        $form = $this->formFactory->create(UserType::class, $user, ['is_edit' => false]);

        self::assertContains(
            'ROLE_USER',
            $form->get('roles')->getData() ?? [],
            'New user form should default to ROLE_USER pre-checked',
        );
        self::assertTrue(
            $form->get('isActive')->getData(),
            'New user form should default isActive to true',
        );
    }
}
