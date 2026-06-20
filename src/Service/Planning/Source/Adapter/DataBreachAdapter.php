<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\DataBreach;
use App\Entity\Tenant;
use App\Enum\DataBreachStatus;
use App\Repository\DataBreachRepository;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;
use DateTime;

/**
 * SourceAdapter for Data Breaches (GDPR Art. 33/34, NIS2 Art. 23).
 *
 * Deadline field : derived — detectedAt + 72 hours (GDPR Art. 33 supervisory
 *                  authority notification window).
 * Terminal statuses: AuthorityNotified, SubjectsNotified, Closed
 *   (Draft, UnderAssessment still require immediate action)
 */
final class DataBreachAdapter implements SourceAdapter
{
    public function __construct(
        private readonly DataBreachRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'data_breach';
    }

    public function label(): string
    {
        return 'Datenpanne';
    }

    public function requiredModule(): string
    {
        return 'privacy';
    }

    /** @return iterable<DataBreach> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findBy(['tenant' => $tenant]);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof DataBreach);
        $detectedAt = $item->getDetectedAt();
        if ($detectedAt === null) {
            return null;
        }
        // GDPR Art. 33: supervisory authority must be notified within 72 hours
        return DateTime::createFromInterface($detectedAt)->modify('+72 hours');
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof DataBreach);
        return $item->getTitle() ?? '#' . $item->getId();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof DataBreach);
        return in_array($item->getStatusEnum(), [
            DataBreachStatus::AuthorityNotified,
            DataBreachStatus::SubjectsNotified,
            DataBreachStatus::Closed,
        ], true);
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof DataBreach);
        return (int) $item->getId();
    }
}
