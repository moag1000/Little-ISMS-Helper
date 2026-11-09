<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\AuditFinding;
use App\Entity\Tenant;
use App\Repository\AuditFindingRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for AuditFinding (ISO 27001 Clause 10.1 structured findings).
 *
 * Deadline field : dueDate
 * Terminal statuses: resolved, verified, closed
 *   (derived from AuditFinding::isOverdue() which excludes these three from
 *    the overdue check, and STATUS_CLOSED/STATUS_VERIFIED/STATUS_RESOLVED
 *    constants on the entity)
 */
final class AuditFindingAdapter implements SourceAdapter
{
    public function __construct(
        private readonly AuditFindingRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'audit_finding';
    }

    public function label(): string
    {
        return 'Audit-Feststellung';
    }

    /** The record page lives in this module; with it off the reminder would open a "module off" page (W6). */
    public function requiredModule(): string
    {
        return 'audits';
    }

    /** @return iterable<AuditFinding> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findBy(['tenant' => $tenant]);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof AuditFinding);
        return $item->getDueDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof AuditFinding);
        return $item->getTitle() ?? '#' . $item->getId();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof AuditFinding);
        return $item->isDone();
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof AuditFinding);
        return (int) $item->getId();
    }

    /** @return iterable<AuditFinding> */
    public function findOpen(Tenant $tenant): iterable
    {
        return $this->findConvertible($tenant);
    }

    public function supports(object $item): bool
    {
        return $item instanceof AuditFinding;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof AuditFinding);

        return Responsibility::holders($item->getAssignedTo(), $item->getAssignedPerson())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof AuditFinding);

        return new RecordLink('app_audit_finding_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'findings', leadDays: 14, normReference: 'ISO 27001 10.2');
    }
}
