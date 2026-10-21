<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real-DB coverage of the count query behind the four-eyes
 * single-approver escape ({@see UserRepository::countActiveInTenantHoldingAny()}):
 * stored roles, custom Role entities, explicit ids, the excluded initiator,
 * inactive users and other organisations.
 */
final class UserRepositoryEligibleCountTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repository;
    private ?Tenant $tenant = null;
    private ?Tenant $otherTenant = null;
    private ?Role $customRole = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(UserRepository::class);

        $uid = uniqid('', true);
        $this->tenant = $this->tenant('Eligible count ' . $uid, 'ec_' . $uid);
        $this->otherTenant = $this->tenant('Eligible count other ' . $uid, 'eco_' . $uid);

        $this->customRole = new Role();
        $this->customRole->setName('ROLE_EC_CUSTOM_' . strtoupper(str_replace('.', '', $uid)));
        $this->customRole->setDescription('Custom approver role (test)');
        $this->em->persist($this->customRole);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ([$this->tenant?->getId(), $this->otherTenant?->getId()] as $tenantId) {
            if ($tenantId === null) {
                continue;
            }
            $conn->executeStatement(
                'DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE tenant_id = ?)',
                [$tenantId],
            );
            $conn->executeStatement('DELETE FROM users WHERE tenant_id = ?', [$tenantId]);
            $conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$tenantId]);
        }
        if ($this->customRole?->getId() !== null) {
            $conn->executeStatement('DELETE FROM roles WHERE id = ?', [$this->customRole->getId()]);
        }
        $this->tenant = $this->otherTenant = $this->customRole = null;

        parent::tearDown();
    }

    #[Test]
    public function countsStoredRolesCustomRolesAndIdsOfActiveUsersOfTheTenantOnly(): void
    {
        $initiator = $this->user($this->tenant, ['ROLE_MANAGER']);
        $this->user($this->tenant, ['ROLE_DPO']);
        $this->user($this->tenant, ['ROLE_DPO'], active: false);
        $this->user($this->otherTenant, ['ROLE_DPO']);
        $withCustomRole = $this->user($this->tenant, []);
        $withCustomRole->addCustomRole($this->customRole);
        $named = $this->user($this->tenant, ['ROLE_AUDITOR']);
        $this->em->flush();

        self::assertSame(2, $this->repository->countActiveInTenantHoldingAny($this->tenant, ['ROLE_MANAGER', 'ROLE_DPO']));
        self::assertSame(
            1,
            $this->repository->countActiveInTenantHoldingAny($this->tenant, ['ROLE_MANAGER', 'ROLE_DPO'], [], $initiator->getId()),
            'the initiator is excluded, the inactive DPO and the other organisation are not counted',
        );
        self::assertSame(1, $this->repository->countActiveInTenantHoldingAny($this->tenant, [(string) $this->customRole->getName()]));
        self::assertSame(
            2,
            $this->repository->countActiveInTenantHoldingAny($this->tenant, ['ROLE_DPO'], [(int) $named->getId(), (int) $initiator->getId()], $initiator->getId()),
        );
        self::assertSame(0, $this->repository->countActiveInTenantHoldingAny($this->tenant, ['ROLE_CISO']));
        self::assertSame(0, $this->repository->countActiveInTenantHoldingAny($this->tenant, []));
    }

    private function tenant(string $name, string $code): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($name);
        $tenant->setCode($code);
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $roles */
    private function user(?Tenant $tenant, array $roles, bool $active = true): User
    {
        $user = new User();
        $user->setEmail('ec_' . uniqid('', true) . '@example.com');
        $user->setFirstName('Eligible');
        $user->setLastName('Count');
        $user->setRoles($roles);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive($active);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
