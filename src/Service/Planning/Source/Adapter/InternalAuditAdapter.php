<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\InternalAudit;
use App\Entity\Tenant;
use App\Enum\InternalAuditStatus;
use App\Repository\InternalAuditRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for InternalAudit (ISO 27001 Clause 9.2).
 *
 * Deadline field : plannedDate
 * Terminal statuses: closed, cancelled
 *   (derived from InternalAudit::LIFECYCLE_STAGES — only 'closed' and
 *    'cancelled' have an empty transitions list, i.e. no exit path.
 *    Legacy buckets 'completed' and 'in_progress' are NOT terminal because
 *    they still transition to 'reported'; 'rejected' transitions back to
 *    'reported' for rework.)
 */
final class InternalAuditAdapter implements SourceAdapter
{
    public function __construct(
        private readonly InternalAuditRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'internal_audit';
    }

    public function label(): string
    {
        return 'Internes Audit';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return iterable<InternalAudit> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findBy(['tenant' => $tenant]);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof InternalAudit);
        return $item->getPlannedDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof InternalAudit);
        return $item->getTitle() ?? '#' . $item->getId();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof InternalAudit);
        return in_array($item->getStatus(), [
            InternalAuditStatus::Closed->value,
            InternalAuditStatus::Cancelled->value,
        ], true);
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof InternalAudit);
        return (int) $item->getId();
    }

    /** @return iterable<InternalAudit> */
    public function findOpen(Tenant $tenant): iterable
    {
        return $this->findConvertible($tenant);
    }

    public function supports(object $item): bool
    {
        return $item instanceof InternalAudit;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof InternalAudit);

        return Responsibility::holders($item->getLeadAuditorUser(), $item->getLeadAuditorPerson())
            ->withDeputies(...$item->getAuditTeamMembers())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof InternalAudit);

        return new RecordLink('app_audit_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'audits_upcoming', leadDays: 14, normReference: 'ISO 27001 9.2');
    }
}
