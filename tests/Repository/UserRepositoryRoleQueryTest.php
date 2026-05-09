<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Form-Audit follow-up (May 2026) — surfaces the new role-scoped query
 * helpers used by Policy-Wizard step partials.
 *
 * Doctrine `Query` is final and cannot be mocked, so this is a unit-
 * level signature + return-type harness (mirroring UserRepositoryTest).
 * Behavioural coverage lives in PolicyWizard step tests + future
 * UserRepositoryIntegrationTest (KernelTestCase + real DB).
 */
#[AllowMockObjectsWithoutExpectations]
final class UserRepositoryRoleQueryTest extends TestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->name = User::class;
        $entityManager->method('getClassMetadata')->willReturn($classMetadata);

        $this->repository = new UserRepository($registry);
    }

    #[Test]
    public function testFindByRoleInTenantSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByRoleInTenant');
        $params = $method->getParameters();

        self::assertCount(2, $params);
        self::assertSame('role', $params[0]->getName());
        self::assertSame('string', $params[0]->getType()?->getName());

        self::assertSame('tenant', $params[1]->getName());
        self::assertTrue($params[1]->isOptional());

        $returnType = $method->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('array', $returnType->getName());
    }

    #[Test]
    public function testFindApproversInTenantSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findApproversInTenant');
        $params = $method->getParameters();

        self::assertCount(1, $params);
        self::assertSame('tenant', $params[0]->getName());
        self::assertTrue($params[0]->isOptional());

        $returnType = $method->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('array', $returnType->getName());
    }

    #[Test]
    public function testRepositoryExposesNewMethods(): void
    {
        self::assertTrue(method_exists($this->repository, 'findByRoleInTenant'));
        self::assertTrue(method_exists($this->repository, 'findApproversInTenant'));
    }
}
