<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\AuditFinding;
use App\Entity\Tenant;
use App\Repository\AuditFindingRepository;
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

    public function requiredModule(): ?string
    {
        return null;
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
        return in_array($item->getStatus(), [
            AuditFinding::STATUS_RESOLVED,
            AuditFinding::STATUS_VERIFIED,
            AuditFinding::STATUS_CLOSED,
        ], true);
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
}
