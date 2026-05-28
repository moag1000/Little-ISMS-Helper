<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AuditProgram;
use App\Repository\AuditProgramRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2.5 — AuditProgramRepository smoke tests.
 */
final class AuditProgramRepositoryTest extends TestCase
{
    #[Test]
    public function repositoryClassExists(): void
    {
        self::assertTrue(class_exists(AuditProgramRepository::class));
    }

    #[Test]
    public function repositoryExtendsServiceEntityRepository(): void
    {
        self::assertTrue(
            is_a(AuditProgramRepository::class, ServiceEntityRepository::class, true),
        );
    }

    #[Test]
    public function findAllByTenantMethodExists(): void
    {
        self::assertTrue(method_exists(AuditProgramRepository::class, 'findAllByTenant'));
    }

    #[Test]
    public function findActiveByTenantMethodExists(): void
    {
        self::assertTrue(method_exists(AuditProgramRepository::class, 'findActiveByTenant'));
    }

    #[Test]
    public function findByStatusAndTenantMethodExists(): void
    {
        self::assertTrue(method_exists(AuditProgramRepository::class, 'findByStatusAndTenant'));
    }

    #[Test]
    public function entityDefaultStatusIsPlanning(): void
    {
        $program = new AuditProgram();
        self::assertSame('planning', $program->getStatus());
    }

    #[Test]
    public function entityHasFourValidStatuses(): void
    {
        $statuses = [
            AuditProgram::STATUS_PLANNING,
            AuditProgram::STATUS_ACTIVE,
            AuditProgram::STATUS_COMPLETED,
            AuditProgram::STATUS_ARCHIVED,
        ];

        self::assertCount(4, $statuses);
        self::assertContains('planning', $statuses);
        self::assertContains('active', $statuses);
        self::assertContains('completed', $statuses);
        self::assertContains('archived', $statuses);
    }
}
