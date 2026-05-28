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
 * Entity invariants for AuditProgram (ISO 19011 §5.4 Audit Programme).
 *
 * Targeted tests: entity getters/setters + status helper methods.
 * Run with: php bin/phpunit tests/Entity/AuditProgramTest.php
 */
final class AuditProgramTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaultStatus(): void
    {
        $program = new AuditProgram();
        self::assertSame('planning', $program->getStatus());
    }

    #[Test]
    public function constructorSetsCreatedAt(): void
    {
        $before = new DateTimeImmutable();
        $program = new AuditProgram();
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $program->getCreatedAt());
        self::assertLessThanOrEqual($after, $program->getCreatedAt());
    }

    #[Test]
    public function constructorSetsDefaultDates(): void
    {
        $program = new AuditProgram();
        self::assertNotNull($program->getStartDate());
        self::assertNotNull($program->getEndDate());
        // Start should be January 1 of current year
        self::assertSame('01', $program->getStartDate()->format('d'));
        self::assertSame('01', $program->getStartDate()->format('m'));
        // End should be December 31 of current year
        self::assertSame('31', $program->getEndDate()->format('d'));
        self::assertSame('12', $program->getEndDate()->format('m'));
    }

    #[Test]
    public function lockVersionDefaultsToZero(): void
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
    public function nameGetterAndSetter(): void
    {
        $program = new AuditProgram();
        $program->setName('ISMS Audit Programme 2026');
        self::assertSame('ISMS Audit Programme 2026', $program->getName());
    }

    #[Test]
    public function descriptionGetterAndSetter(): void
    {
        $program = new AuditProgram();
        $program->setDescription('Annual ISMS audit');
        self::assertSame('Annual ISMS audit', $program->getDescription());
        $program->setDescription(null);
        self::assertNull($program->getDescription());
    }

    #[Test]
    public function scopeGetterAndSetter(): void
    {
        $program = new AuditProgram();
        $program->setScope('Full ISMS scope per ISO 27001');
        self::assertSame('Full ISMS scope per ISO 27001', $program->getScope());
    }

    #[Test]
    public function objectivesGetterAndSetter(): void
    {
        $program = new AuditProgram();
        $program->setObjectives('Verify ISO 27001 compliance');
        self::assertSame('Verify ISO 27001 compliance', $program->getObjectives());
    }

    #[Test]
    public function frequencyGetterAndSetter(): void
    {
        $program = new AuditProgram();
        $program->setFrequency('annual');
        self::assertSame('annual', $program->getFrequency());
        $program->setFrequency(null);
        self::assertNull($program->getFrequency());
    }

    #[Test]
    public function riskCategoriesGetterAndSetter(): void
    {
        $program = new AuditProgram();
        $categories = ['IT security', 'Physical access', 'Personnel'];
        $program->setRiskCategories($categories);
        self::assertSame($categories, $program->getRiskCategories());
        $program->setRiskCategories(null);
        self::assertNull($program->getRiskCategories());
    }

    #[Test]
    public function statusGetterAndSetter(): void
    {
        $program = new AuditProgram();
        $program->setStatus('active');
        self::assertSame('active', $program->getStatus());
        $program->setStatus('completed');
        self::assertSame('completed', $program->getStatus());
        $program->setStatus('archived');
        self::assertSame('archived', $program->getStatus());
    }

    #[Test]
    public function statusToneReturnsCorrectTone(): void
    {
        $program = new AuditProgram();

        $program->setStatus('planning');
        self::assertSame('neutral', $program->getStatusTone());

        $program->setStatus('active');
        self::assertSame('primary', $program->getStatusTone());

        $program->setStatus('completed');
        self::assertSame('success', $program->getStatusTone());

        $program->setStatus('archived');
        self::assertSame('neutral', $program->getStatusTone());
    }

    #[Test]
    public function tenantGetterAndSetter(): void
    {
        $program = new AuditProgram();
        $tenant = new Tenant();
        $program->setTenant($tenant);
        self::assertSame($tenant, $program->getTenant());
        $program->setTenant(null);
        self::assertNull($program->getTenant());
    }

    #[Test]
    public function programmeOwnerGetterAndSetter(): void
    {
        $program = new AuditProgram();
        $user = new User();
        $program->setProgrammeOwner($user);
        self::assertSame($user, $program->getProgrammeOwner());
        $program->setProgrammeOwner(null);
        self::assertNull($program->getProgrammeOwner());
    }

    #[Test]
    public function effectiveOwnerNameReturnsNullWhenNoOwner(): void
    {
        $program = new AuditProgram();
        self::assertNull($program->getEffectiveOwnerName());
    }

    #[Test]
    public function updatedAtGetterAndSetter(): void
    {
        $program = new AuditProgram();
        self::assertNull($program->getUpdatedAt());
        $now = new DateTimeImmutable();
        $program->setUpdatedAt($now);
        self::assertSame($now, $program->getUpdatedAt());
    }

    #[Test]
    public function archivedAtGetterAndSetter(): void
    {
        $program = new AuditProgram();
        self::assertNull($program->getArchivedAt());
        $now = new DateTimeImmutable();
        $program->setArchivedAt($now);
        self::assertSame($now, $program->getArchivedAt());
    }

    #[Test]
    public function createdByGetterAndSetter(): void
    {
        $program = new AuditProgram();
        $user = new User();
        $program->setCreatedBy($user);
        self::assertSame($user, $program->getCreatedBy());
    }

    #[Test]
    public function internalAuditsCollectionIsEmptyByDefault(): void
    {
        $program = new AuditProgram();
        self::assertCount(0, $program->getInternalAudits());
    }

    #[Test]
    public function auditCountReturnsZeroByDefault(): void
    {
        $program = new AuditProgram();
        self::assertSame(0, $program->getAuditCount());
    }

    #[Test]
    public function toStringReturnsNameWhenSet(): void
    {
        $program = new AuditProgram();
        $program->setName('Test Programme');
        self::assertSame('Test Programme', (string) $program);
    }

    #[Test]
    public function toStringReturnsFallbackWhenNameNotSet(): void
    {
        $program = new AuditProgram();
        self::assertStringStartsWith('AuditProgram #', (string) $program);
    }
}
