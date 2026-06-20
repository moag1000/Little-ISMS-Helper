<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\DataProtectionImpactAssessment;
use App\Entity\Tenant;
use App\Enum\DpiaStatus;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for Data Protection Impact Assessments (GDPR Art. 35/36).
 *
 * Deadline field : nextReviewDate — GDPR Art. 35(9) mandates periodic review
 *                  when circumstances change. The review date is the primary
 *                  SLA anchor for the planning action.
 * Terminal statuses: Approved, Rejected (review cycle is complete or inactive)
 *   (Draft, InReview, RequiresRevision still require active work)
 *
 * ownsRecurrence = true because GDPR Art. 35(9) requires periodic re-evaluation
 * and the DPIA entity carries the next-review cadence itself.
 */
final class DpiaAdapter implements SourceAdapter
{
    public function __construct(
        private readonly DataProtectionImpactAssessmentRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'dpia';
    }

    public function label(): string
    {
        return 'Datenschutz-Folgenabschätzung (DPIA)';
    }

    public function requiredModule(): string
    {
        return 'privacy';
    }

    /** @return iterable<DataProtectionImpactAssessment> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findBy(['tenant' => $tenant]);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof DataProtectionImpactAssessment);
        return $item->getNextReviewDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof DataProtectionImpactAssessment);
        return $item->getTitle() ?? '#' . $item->getId();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof DataProtectionImpactAssessment);
        return in_array($item->getStatusEnum(), [
            DpiaStatus::Approved,
            DpiaStatus::Rejected,
        ], true);
    }

    public function ownsRecurrence(): bool
    {
        return true;
    }

    public function refId(object $item): int
    {
        assert($item instanceof DataProtectionImpactAssessment);
        return (int) $item->getId();
    }
}
