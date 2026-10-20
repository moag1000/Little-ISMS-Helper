<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\MfaToken;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\MfaTokenType;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * MfaTokenType: the user choices are the users the actor may EDIT
 * (UserVoter::EDIT) — an organisation admin never sees or accepts users of
 * another organisation or SUPER_ADMIN accounts.
 */
final class MfaTokenTypeScopeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $tenantA;
    private User $admin;
    private User $colleague;
    private User $superAdmin;
    private User $foreigner;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = str_replace('.', '', uniqid('mfa', true));

        $this->tenantA = (new Tenant())->setName('MFA A ' . $uid)->setCode('mfaa_' . $uid);
        $tenantB = (new Tenant())->setName('MFA B ' . $uid)->setCode('mfab_' . $uid);
        $this->em->persist($this->tenantA);
        $this->em->persist($tenantB);
        $this->admin = $this->makeUser('admin_' . $uid, $this->tenantA, ['ROLE_ADMIN']);
        $this->colleague = $this->makeUser('colleague_' . $uid, $this->tenantA, ['ROLE_USER']);
        $this->superAdmin = $this->makeUser('super_' . $uid, $this->tenantA, ['ROLE_SUPER_ADMIN']);
        $this->foreigner = $this->makeUser('foreign_' . $uid, $tenantB, ['ROLE_USER']);
        $this->em->flush();

        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($this->admin, 'main', $this->admin->getRoles()));
        static::getContainer()->get(TenantContext::class)->setCurrentTenant($this->tenantA);
    }

    /** @param list<string> $roles */
    private function makeUser(string $name, Tenant $tenant, array $roles): User
    {
        $user = (new User())
            ->setEmail($name . '@example.com')
            ->setFirstName('First')
            ->setLastName($name)
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function form(): \Symfony\Component\Form\FormInterface
    {
        return static::getContainer()->get(FormFactoryInterface::class)
            ->create(MfaTokenType::class, new MfaToken(), ['csrf_protection' => false]);
    }

    #[Test]
    public function onlyUsersTheAdminMayEditAreOffered(): void
    {
        $values = array_map(
            static fn ($choice): string => (string) $choice->value,
            $this->form()->createView()['user']->vars['choices'],
        );

        self::assertContains((string) $this->colleague->getId(), $values);
        self::assertContains((string) $this->admin->getId(), $values, 'self-service stays possible');
        self::assertNotContains((string) $this->foreigner->getId(), $values, 'user of another organisation offered');
        self::assertNotContains((string) $this->superAdmin->getId(), $values, 'SUPER_ADMIN account offered to an admin');
    }

    #[Test]
    public function tamperedSubmitOfAForeignUserIsRejected(): void
    {
        $form = $this->form();
        $form->submit(['user' => (string) $this->foreigner->getId()], false);

        self::assertFalse($form->get('user')->isValid());
        self::assertNull($form->getData()->getUser());
    }

    #[Test]
    public function theQueryIsPreRestrictedToTheActorsOrganisationTree(): void
    {
        $qb = $this->form()->get('user')->getConfig()->getOption('query_builder');
        self::assertInstanceOf(\Doctrine\ORM\QueryBuilder::class, $qb);
        $ids = $qb->getParameter('mfaTenantIds')?->getValue();

        self::assertIsArray($ids, 'admin query must be restricted to its organisation tree');
        self::assertContains($this->tenantA->getId(), $ids);
        self::assertNotContains($this->foreigner->getTenant()?->getId(), $ids);
    }
}
