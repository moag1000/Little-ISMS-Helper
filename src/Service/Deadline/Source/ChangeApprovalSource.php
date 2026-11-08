<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\ChangeRequest;
use App\Entity\Tenant;
use App\Repository\ChangeRequestRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Change requests waiting for approval (ISO 27001 A.8.32): a decision for the
 * security officers, due by the planned implementation date.
 *
 * One change request belongs to one source at a time: this one while it waits
 * for approval, {@see \App\Service\Planning\Source\Adapter\ChangeRequestAdapter}
 * (implementation by the planned date) afterwards. Both answer supports()
 * by status, so "who is responsible for this change" has one answer.
 */
final class ChangeApprovalSource extends EntityDeadlineSource
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly ChangeRequestRepository $changeRequests,
    ) {
        parent::__construct($entityManager);
    }

    protected function entityClass(): string
    {
        return ChangeRequest::class;
    }

    public function slug(): string
    {
        return 'change_approval';
    }

    public function label(): string
    {
        return 'Change-Freigabe';
    }

    /** Statuses in which a change waits for its approval. */
    public const array PENDING = ['submitted', 'under_review'];

    public function requiredModule(): string
    {
        return 'change_requests';
    }

    public function supports(object $item): bool
    {
        return $item instanceof ChangeRequest && in_array($item->getStatus(), self::PENDING, true);
    }

    public function findOpen(Tenant $tenant): iterable
    {
        return $this->changeRequests->findPendingApprovalByTenant($tenant);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ChangeRequest);

        return $item->getPlannedImplementationDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ChangeRequest);

        return sprintf('%s — %s', (string) ($item->getChangeNumber() ?? '#' . $item->getId()), (string) ($item->getTitle() ?? '—'));
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof ChangeRequest);

        return !in_array($item->getStatus(), self::PENDING, true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        return Responsibility::roles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof ChangeRequest);

        return new RecordLink('app_change_request_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(
            bucket: 'change_requests_pending_approval',
            leadDays: null,
            normReference: 'ISO 27001 A.8.32',
            decision: true,
        );
    }
}
