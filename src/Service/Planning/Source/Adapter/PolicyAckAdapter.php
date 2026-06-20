<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Repository\PolicyAcknowledgementRepository;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for Policy Acknowledgements (ISO 27001 A.6.3 — awareness &
 * communication).
 *
 * Deadline field : none — acknowledgements have no hard deadline in the entity.
 * Terminal        : status = acknowledged
 *   (pending rows represent users who have not yet confirmed the policy)
 *
 * The route links to the inbox (`app_policy_ack_inbox`) because there is no
 * per-item detail page — users acknowledge policies via the shared inbox UI.
 */
final class PolicyAckAdapter implements SourceAdapter
{
    public function __construct(
        private readonly PolicyAcknowledgementRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'policy_ack';
    }

    public function label(): string
    {
        return 'Richtlinien-Bestätigung';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return iterable<PolicyAcknowledgement> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findBy([
            'tenant' => $tenant,
            'status' => PolicyAcknowledgement::STATUS_PENDING,
        ]);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        // No deadline field on PolicyAcknowledgement
        return null;
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof PolicyAcknowledgement);
        return $item->getDocument()?->getOriginalFilename() ?? 'Policy';
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof PolicyAcknowledgement);
        return $item->getStatus() === PolicyAcknowledgement::STATUS_ACKNOWLEDGED;
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof PolicyAcknowledgement);
        return (int) $item->getId();
    }
}
