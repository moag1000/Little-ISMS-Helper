<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\Tenant;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for Four-Eyes Approval Requests (BSI-aligned dual-control
 * principle, ISO 27001 A.8.18).
 *
 * Deadline field : expiresAt — the request expires after 7 days by default
 *                  (set in constructor). Used as the SLA anchor.
 * Terminal statuses: approved, rejected, expired
 *   (only pending requests still require the second approver's action)
 *
 * The route links to the inbox (`app_four_eyes_inbox`) because there is no
 * per-item detail page — approvers act on requests via the shared inbox UI.
 */
final class FourEyesAdapter implements SourceAdapter
{
    public function __construct(
        private readonly FourEyesApprovalRequestRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'four_eyes';
    }

    public function label(): string
    {
        return '4-Augen-Freigabe';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return iterable<FourEyesApprovalRequest> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findBy([
            'tenant' => $tenant,
            'status' => FourEyesApprovalRequest::STATUS_PENDING,
        ]);
    }

    public function dueDateOf(object $item): DateTimeInterface
    {
        assert($item instanceof FourEyesApprovalRequest);
        return $item->getExpiresAt();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof FourEyesApprovalRequest);
        return $item->getActionType();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof FourEyesApprovalRequest);
        return $item->getStatus() !== FourEyesApprovalRequest::STATUS_PENDING;
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof FourEyesApprovalRequest);
        return (int) $item->getId();
    }
}
