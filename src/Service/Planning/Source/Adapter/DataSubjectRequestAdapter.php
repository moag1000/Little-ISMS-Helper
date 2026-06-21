<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\DataSubjectRequest;
use App\Entity\Tenant;
use App\Repository\DataSubjectRequestRepository;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for GDPR Data Subject Requests (Art. 12–22 GDPR).
 *
 * Deadline field : deadlineAt — Art. 12(3) 30-day response deadline,
 *                  auto-calculated from receivedAt on pre-persist.
 * Terminal statuses: completed, rejected
 *   (received, identity_verification, in_progress, extended still require action)
 */
final class DataSubjectRequestAdapter implements SourceAdapter
{
    public function __construct(
        private readonly DataSubjectRequestRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'dsr';
    }

    public function label(): string
    {
        return 'Betroffenenantrag (DSR)';
    }

    public function requiredModule(): string
    {
        return 'privacy';
    }

    /** @return iterable<DataSubjectRequest> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findConvertibleForTenant($tenant);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof DataSubjectRequest);
        return $item->getDeadlineAt();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof DataSubjectRequest);
        return sprintf('DSR #%d — %s', (int) $item->getId(), (string) $item->getRequestType());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof DataSubjectRequest);
        return in_array($item->getStatus(), ['completed', 'rejected'], true);
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof DataSubjectRequest);
        return (int) $item->getId();
    }
}
