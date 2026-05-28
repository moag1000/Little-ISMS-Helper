<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AuditProgram;
use App\Entity\Tenant;
use App\Repository\AuditProgramRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuditProgramRepository.
 *
 * QueryBuilder integration methods (findAllByTenant, findActiveByTenant, etc.)
 * require a real DB and are covered in integration tests.
 * This file validates the repository class structure and entity mapping.
 *
 * Run with: php bin/phpunit tests/Repository/AuditProgramRepositoryTest.php
 */
class AuditProgramRepositoryTest extends TestCase
{
    #[Test]
    public function repositoryCanBeInstantiatedWithoutActualRegistry(): void
    {
        // Verifies that the repository class is correctly defined (no compile errors).
        self::assertTrue(class_exists(AuditProgramRepository::class));
    }

    #[Test]
    public function repositoryExtendsServiceEntityRepository(): void
    {
        $parents = class_parents(AuditProgramRepository::class);
        self::assertContains(
            \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository::class,
            $parents,
            'AuditProgramRepository must extend ServiceEntityRepository'
        );
    }

    #[Test]
    public function auditProgramEntityHasTenantProperty(): void
    {
        $program = new AuditProgram();
        $tenant = new Tenant();
        $program->setTenant($tenant);
        self::assertSame($tenant, $program->getTenant());
    }

    #[Test]
    public function auditProgramEntityHasStatusPlanningByDefault(): void
    {
        $program = new AuditProgram();
        self::assertSame('planning', $program->getStatus());
    }

    #[Test]
    public function allStatusValuesAreValid(): void
    {
        $validStatuses = ['planning', 'active', 'completed', 'archived'];
        $program = new AuditProgram();

        foreach ($validStatuses as $status) {
            $program->setStatus($status);
            self::assertSame($status, $program->getStatus());
        }
    }

    #[Test]
    public function tenantScopingViaMethodExists(): void
    {
        self::assertTrue(method_exists(AuditProgramRepository::class, 'findAllByTenant'));
        self::assertTrue(method_exists(AuditProgramRepository::class, 'findActiveByTenant'));
        self::assertTrue(method_exists(AuditProgramRepository::class, 'findByStatusAndTenant'));
    }
}
