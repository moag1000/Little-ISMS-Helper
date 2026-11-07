<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\AuditProgram;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use App\Util\Cadence;
use App\Util\LegalPeriod;
use DateTimeInterface;

/**
 * Auditprogramm (ISO 27001 9.2.2): the end of a running programme; after a
 * completed one with a frequency, the next programme is due one interval
 * after its end ({@see Cadence}) until a successor programme exists.
 */
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

        $end = $item->getEndDate();
        $months = $this->followUpMonths($item);

        return $end !== null && $months !== null ? LegalPeriod::addMonths($end, $months) : $end;
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof AuditProgram);

        $name = (string) ($item->getName() ?? 'Audit programme #' . $item->getId());

        return $this->followUpMonths($item) !== null ? 'Folgeprogramm nach ' . $name : $name;
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof AuditProgram);

        if ($item->getArchivedAt() !== null || $item->getStatus() === AuditProgram::STATUS_ARCHIVED) {
            return true;
        }
        if ($item->getStatus() !== AuditProgram::STATUS_COMPLETED) {
            return false;
        }

        return $this->followUpMonths($item) === null || $this->hasSuccessor($item);
    }

    /** Months to the next programme of a completed one, null when it does not recur. */
    private function followUpMonths(AuditProgram $program): ?int
    {
        return $program->getStatus() === AuditProgram::STATUS_COMPLETED ? Cadence::months($program->getFrequency()) : null;
    }

    private function hasSuccessor(AuditProgram $program): bool
    {
        $end = $program->getEndDate();
        if ($end === null) {
            return false;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(AuditProgram::class, 'p')
            ->where('p.tenant = :tenant')
            ->andWhere('p.id != :id')
            ->andWhere('p.startDate >= :end')
            ->setParameter('tenant', $program->getTenant())
            ->setParameter('id', $program->getId())
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() > 0;
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
