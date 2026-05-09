<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Person;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Person-Rollout (2026-05-08) — verifies the new query helpers added
 * to PersonRepository for the Step-4 Roles person-picker.
 *
 * Doctrine `Query` is final and cannot be mocked, so this is a unit-
 * level signature + null-safety harness (mirroring
 * {@see UserRepositoryRoleQueryTest}). End-to-end coverage lands in
 * the PolicyWizard step tests once a database fixture is available.
 */
#[AllowMockObjectsWithoutExpectations]
final class PersonRepositoryRolloutTest extends TestCase
{
    private PersonRepository $repository;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        $meta = $this->createMock(ClassMetadata::class);
        $meta->name = Person::class;
        $em->method('getClassMetadata')->willReturn($meta);

        $this->repository = new PersonRepository($registry);
    }

    #[Test]
    public function findActiveByTenantReturnsEmptyArrayForNullTenant(): void
    {
        self::assertSame([], $this->repository->findActiveByTenant(null));
    }

    #[Test]
    public function findRoleHoldersByTenantReturnsEmptyArrayForNullTenant(): void
    {
        self::assertSame([], $this->repository->findRoleHoldersByTenant(null));
        self::assertSame([], $this->repository->findRoleHoldersByTenant(null, 'consultant'));
    }

    #[Test]
    public function findOneByLinkedUserIdRejectsZeroOrNegativeIds(): void
    {
        self::assertNull($this->repository->findOneByLinkedUserId(0));
        self::assertNull($this->repository->findOneByLinkedUserId(-1));
    }

    #[Test]
    public function newRolloutMethodsExposed(): void
    {
        self::assertTrue(method_exists($this->repository, 'findActiveByTenant'));
        self::assertTrue(method_exists($this->repository, 'findRoleHoldersByTenant'));
        self::assertTrue(method_exists($this->repository, 'findOneByLinkedUserId'));

        $reflection = new \ReflectionMethod($this->repository, 'findRoleHoldersByTenant');
        self::assertCount(2, $reflection->getParameters());
        self::assertTrue($reflection->getParameters()[1]->isOptional());

        $linkedUserMethod = new \ReflectionMethod($this->repository, 'findOneByLinkedUserId');
        self::assertSame('int', $linkedUserMethod->getParameters()[0]->getType()?->getName());
    }
}
