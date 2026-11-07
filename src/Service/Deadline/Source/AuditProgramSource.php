<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\AuditProgram;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Auditprogramm: getEndDate() of AuditProgram. */
final class AuditProgramSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return AuditProgram::class;
    }

    public function slug(): string
    {
        return 'audit_program';
    }

    public function label(): string
    {
        return 'Auditprogramm';
    }

    public function requiredModule(): string
    {
        return 'audits';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof AuditProgram);

        return $item->getEndDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof AuditProgram);

        return (string) ($item->getName() ?? 'Audit programme #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof AuditProgram);

        return $item->getStatus() === \App\Entity\AuditProgram::STATUS_COMPLETED || $item->getArchivedAt() !== null;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof AuditProgram);

        return Responsibility::holders($item->getProgrammeOwner())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof AuditProgram);

        return new RecordLink('app_audit_program_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'audit_program_due', leadDays: 30, normReference: 'ISO 27001 9.2.2');
    }
}
