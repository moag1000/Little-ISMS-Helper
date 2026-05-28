<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AuditProgram;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2.5 — AuditProgram entity unit tests (ISO 19011 §5.4).
 */
final class AuditProgramTest extends TestCase
{
    #[Test]
    public function defaultStatusIsPlanning(): void
    {
        $program = new AuditProgram();
        self::assertSame(AuditProgram::STATUS_PLANNING, $program->getStatus());
    }

    #[Test]
    public function constructorSetsCreatedAt(): void
    {
        $before  = new DateTimeImmutable();
        $program = new AuditProgram();
        $after   = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $program->getCreatedAt());
        self::assertLessThanOrEqual($after, $program->getCreatedAt());
    }

    #[Test]
    public function constructorSetsDefaultDatesToCurrentYear(): void
    {
        $year    = (int) (new DateTimeImmutable())->format('Y');
        $program = new AuditProgram();

        self::assertSame($year . '-01-01', $program->getStartDate()->format('Y-m-d'));
        self::assertSame($year . '-12-31', $program->getEndDate()->format('Y-m-d'));
    }

    #[Test]
    public function lockVersionIsZeroByDefault(): void
    {
        $program = new AuditProgram();
        self::assertSame(0, $program->getLockVersion());
    }

    #[Test]
    public function idIsNullBeforePersist(): void
    {
        $program = new AuditProgram();
        self::assertNull($program->getId());
    }

    #[Test]
    public function nameRoundTrip(): void
    {
        $program = new AuditProgram();
        $program->setName('ISMS Audit Programme 2026');
        self::assertSame('ISMS Audit Programme 2026', $program->getName());
    }

    #[Test]
    public function statusRoundTrip(): void
    {
        $program = new AuditProgram();
        $program->setStatus(AuditProgram::STATUS_ACTIVE);
        self::assertSame(AuditProgram::STATUS_ACTIVE, $program->getStatus());
    }

    #[Test]
    public function statusToneForPlanning(): void
    {
        $program = new AuditProgram();
        $program->setStatus(AuditProgram::STATUS_PLANNING);
        self::assertSame('neutral', $program->getStatusTone());
    }

    #[Test]
    public function statusToneForActive(): void
    {
        $program = new AuditProgram();
        $program->setStatus(AuditProgram::STATUS_ACTIVE);
        self::assertSame('primary', $program->getStatusTone());
    }

    #[Test]
    public function statusToneForCompleted(): void
    {
        $program = new AuditProgram();
        $program->setStatus(AuditProgram::STATUS_COMPLETED);
        self::assertSame('success', $program->getStatusTone());
    }

    #[Test]
    public function statusToneForArchived(): void
    {
        $program = new AuditProgram();
        $program->setStatus(AuditProgram::STATUS_ARCHIVED);
        self::assertSame('neutral', $program->getStatusTone());
    }

    #[Test]
    public function tenantRoundTrip(): void
    {
        $tenant  = new Tenant();
        $program = new AuditProgram();
        $program->setTenant($tenant);
        self::assertSame($tenant, $program->getTenant());
    }

    #[Test]
    public function programmeOwnerRoundTrip(): void
    {
        $user    = new User();
        $program = new AuditProgram();
        $program->setProgrammeOwner($user);
        self::assertSame($user, $program->getProgrammeOwner());
    }

    #[Test]
    public function effectiveOwnerNameReturnsNullWhenNoOwner(): void
    {
        $program = new AuditProgram();
        self::assertNull($program->getEffectiveOwnerName());
    }

    #[Test]
    public function riskCategoriesRoundTrip(): void
    {
        $program = new AuditProgram();
        $program->setRiskCategories(['access_control', 'physical_security']);
        self::assertSame(['access_control', 'physical_security'], $program->getRiskCategories());
    }

    #[Test]
    public function frequencyRoundTrip(): void
    {
        $program = new AuditProgram();
        $program->setFrequency('annual');
        self::assertSame('annual', $program->getFrequency());
    }

    #[Test]
    public function emptyInternalAuditsCollection(): void
    {
        $program = new AuditProgram();
        self::assertCount(0, $program->getInternalAudits());
        self::assertSame(0, $program->getAuditCount());
    }

    #[Test]
    public function toStringWithName(): void
    {
        $program = new AuditProgram();
        $program->setName('Test Programme');
        self::assertSame('Test Programme', (string) $program);
    }

    #[Test]
    public function toStringFallbackWithoutName(): void
    {
        $program = new AuditProgram();
        self::assertStringStartsWith('AuditProgram#', (string) $program);
    }
}
