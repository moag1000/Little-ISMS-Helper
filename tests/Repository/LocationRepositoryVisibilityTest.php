<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Location;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\LocationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-test contract for LocationRepository::findVisibleForUserAndTenant.
 *
 * Doctrine's `Query` class is final and cannot be mocked, so this test
 * stays at the structural / role-gate layer:
 *   - The method exists on the repository with the documented signature.
 *   - Users without ROLE_USER (or any higher role) get an empty list
 *     WITHOUT a database query being issued (the role-gate short-circuit
 *     fires before the QueryBuilder is built).
 *   - The signature accepts (User, Tenant) and returns array.
 *
 * For end-to-end query behaviour see
 * `LocationRepositoryIntegrationTest` (KernelTestCase) — added if and
 * when the wider integration-test gate gets a Location-specific suite.
 */
#[AllowMockObjectsWithoutExpectations]
class LocationRepositoryVisibilityTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $registry;
    private LocationRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->registry = $this->createMock(ManagerRegistry::class);

        $this->registry->method('getManagerForClass')
            ->willReturn($this->entityManager);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->name = Location::class;
        $this->entityManager->method('getClassMetadata')
            ->willReturn($classMetadata);

        $this->repository = new LocationRepository($this->registry);
    }

    #[Test]
    public function methodExistsWithDocumentedSignature(): void
    {
        $reflection = new \ReflectionMethod(LocationRepository::class, 'findVisibleForUserAndTenant');
        $params = $reflection->getParameters();

        self::assertCount(2, $params);
        self::assertSame('user', $params[0]->getName());
        self::assertSame('tenant', $params[1]->getName());
        self::assertSame(User::class, $params[0]->getType()?->getName());
        self::assertSame(Tenant::class, $params[1]->getType()?->getName());
        self::assertSame('array', $reflection->getReturnType()?->getName());
    }

    #[Test]
    public function userWithoutAnyRoleGetsEmptyListWithoutQuery(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getRoles')->willReturn([]); // no role at all

        $tenant = $this->createStub(Tenant::class);

        // The EntityManager must NEVER receive a createQuery / persist
        // call when the role-gate short-circuits — assert by configuring
        // the mock to throw if either method runs.
        $this->entityManager->expects(self::never())
            ->method('createQuery');

        $result = $this->repository->findVisibleForUserAndTenant($user, $tenant);

        self::assertSame([], $result);
    }

    #[Test]
    public function userWithUnrelatedRoleStillRejected(): void
    {
        // ROLE_NONSENSE is not in the documented allow-list (USER /
        // AUDITOR / MANAGER / ADMIN / SUPER_ADMIN) — repository must
        // refuse without hitting the database.
        $user = $this->createStub(User::class);
        $user->method('getRoles')->willReturn(['ROLE_NONSENSE']);

        $tenant = $this->createStub(Tenant::class);

        $this->entityManager->expects(self::never())
            ->method('createQuery');

        $result = $this->repository->findVisibleForUserAndTenant($user, $tenant);

        self::assertSame([], $result);
    }
}
