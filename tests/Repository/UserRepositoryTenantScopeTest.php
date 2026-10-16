<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Role/id based recipient lookups must never cross the organisation boundary.
 *
 * Regression guard for the removed unscoped `UserRepository::findByRole()`,
 * which selected notification recipients (admins, CISO, DPO, approvers) for
 * tenant-specific records from ALL organisations. Verified with two tenants
 * that both hold users with the same role — with a single tenant the leak is
 * invisible.
 */
final class UserRepositoryTenantScopeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(UserRepository::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    #[Test]
    public function findByRoleInTenantOnlyReturnsUsersOfThatTenant(): void
    {
        $tenantA = $this->tenant('a');
        $tenantB = $this->tenant('b');
        $adminA = $this->user($tenantA, 'ROLE_ADMIN');
        $adminB = $this->user($tenantB, 'ROLE_ADMIN');
        $this->em->flush();

        $resultA = $this->repository->findByRoleInTenant('ROLE_ADMIN', $tenantA);
        $resultB = $this->repository->findByRoleInTenant('ROLE_ADMIN', (int) $tenantB->getId());

        self::assertContains($adminA, $resultA);
        self::assertNotContains($adminB, $resultA);
        self::assertContains($adminB, $resultB);
        self::assertNotContains($adminA, $resultB);
    }

    #[Test]
    public function findByRoleInTenantWithoutTenantReturnsNobody(): void
    {
        $this->user($this->tenant('c'), 'ROLE_ADMIN');
        $this->em->flush();

        self::assertSame([], $this->repository->findByRoleInTenant('ROLE_ADMIN', null));
    }

    #[Test]
    public function findByIdsInTenantDropsUsersOfOtherTenants(): void
    {
        $tenantA = $this->tenant('d');
        $tenantB = $this->tenant('e');
        $userA = $this->user($tenantA, 'ROLE_USER');
        $userB = $this->user($tenantB, 'ROLE_USER');
        $this->em->flush();

        $result = $this->repository->findByIdsInTenant([$userA->getId(), (string) $userB->getId()], $tenantA);

        self::assertSame([$userA], $result);
        self::assertSame([], $this->repository->findByIdsInTenant([$userA->getId()], null));
    }

    private function tenant(string $suffix): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Scope Tenant ' . $suffix);
        $tenant->setCode('urts_' . $suffix . '_' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function user(Tenant $tenant, string $role): User
    {
        $user = new User();
        $user->setEmail('urts_' . uniqid('', true) . '@example.com');
        $user->setFirstName('Scope');
        $user->setLastName('User');
        $user->setRoles([$role]);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}
