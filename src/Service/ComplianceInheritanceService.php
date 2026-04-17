<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\FulfillmentInheritanceLogRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;

/**
 * Orchestrates mapping-based inheritance suggestions with mandatory review.
 *
 * See docs/DATA_REUSE_IMPROVEMENT_PLAN.md v1.1 WS-1 and docs/WS1_UX_DEV_DESIGN.md.
 *
 * Suggestions never write ComplianceRequirementFulfillment.fulfillmentPercentage
 * directly — they land in FulfillmentInheritanceLog with status pending_review.
 * Only confirm/override transitions the value into the fulfillment entity.
 */
class ComplianceInheritanceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly FulfillmentInheritanceLogRepository $inheritanceRepository,
        private readonly FourEyesApprovalService $fourEyesService,
        private readonly AuditLogger $auditLogger,
        private readonly CompliancePolicyService $policy,
    ) {
    }

    private function minCommentLength(): int
    {
        return $this->policy->getInt(CompliancePolicyService::KEY_MIN_COMMENT_LENGTH, 20);
    }

    private function minOverrideReasonLength(): int
    {
        return $this->policy->getInt(CompliancePolicyService::KEY_MIN_OVERRIDE_REASON_LENGTH, 20);
    }

    /**
     * @return array{created: int, skipped: int, logs: list<FulfillmentInheritanceLog>}
     */
    public function createInheritanceSuggestions(
        Tenant $tenant,
        ComplianceFramework $targetFramework,
        User $triggeredBy,
        bool $dryRun = false,
    ): array {
        $targetRequirements = $this->requirementRepository->findBy([
            'complianceFramework' => $targetFramework,
        ]);

        $created = 0;
        $skipped = 0;
        $logs = [];
        $now = new DateTimeImmutable();

        foreach ($targetRequirements as $targetRequirement) {
            $candidates = $this->findActiveSourceMappings($targetRequirement, $tenant, $now);
            if ($candidates === []) {
                continue;
            }

            [$bestMapping, $suggestedPercentage] = $this->pickBestCandidate($candidates);
            if ($bestMapping === null) {
                $skipped++;
                continue;
            }

            $fulfillment = $this->fulfillmentRepository->findOneBy([
                'tenant' => $tenant,
                'complianceRequirement' => $targetRequirement,
            ]);
            if (!$fulfillment instanceof ComplianceRequirementFulfillment) {
                $fulfillment = new ComplianceRequirementFulfillment();
                $fulfillment->setTenant($tenant);
                $fulfillment->setRequirement($targetRequirement);
                if (!$dryRun) {
                    $this->entityManager->persist($fulfillment);
                }
            }

            $existingLog = $this->inheritanceRepository->findOneBy([
                'tenant' => $tenant,
                'fulfillment' => $fulfillment,
                'derivedFromMapping' => $bestMapping,
            ]);
            if ($existingLog instanceof FulfillmentInheritanceLog) {
                $skipped++;
                continue;
            }

            $log = (new FulfillmentInheritanceLog())
                ->setTenant($tenant)
                ->setFulfillment($fulfillment)
                ->setDerivedFromMapping($bestMapping)
                ->setSuggestedPercentage($suggestedPercentage)
                ->setReviewStatus(FulfillmentInheritanceLog::STATUS_PENDING_REVIEW);

            if (!$dryRun) {
                $this->entityManager->persist($log);
            }
            $logs[] = $log;
            $created++;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
            $this->auditLogger->logCustom(
                'compliance.inheritance.batch_created',
                'FulfillmentInheritanceLog',
                null,
                null,
                [
                    'tenant_id' => $tenant->getId(),
                    'framework' => $targetFramework->getCode(),
                    'created' => $created,
                    'skipped' => $skipped,
                    'triggered_by' => $triggeredBy->getId(),
                ],
                sprintf('Batch created %d inheritance suggestions for %s', $created, $targetFramework->getCode()),
            );
        }

        return ['created' => $created, 'skipped' => $skipped, 'logs' => $logs];
    }

    public function confirmInheritance(
        FulfillmentInheritanceLog $log,
        User $reviewer,
        string $comment,
        bool $requestImplementedTransition = false,
        ?User $fourEyesApprover = null,
    ): void {
        $this->assertPending($log);
        $this->assertMinLength($comment, $this->minCommentLength(), 'review_comment');

        $log->setReviewStatus(FulfillmentInheritanceLog::STATUS_CONFIRMED)
            ->setReviewedBy($reviewer)
            ->setReviewedAt(new DateTimeImmutable())
            ->setReviewComment($comment);

        $fulfillment = $log->getFulfillment();
        if ($fulfillment !== null) {
            $fulfillment->setFulfillmentPercentage($log->getSuggestedPercentage());
        }

        if ($requestImplementedTransition) {
            if ($fourEyesApprover === null || $fourEyesApprover->getId() === $reviewer->getId()) {
                throw new InvalidArgumentException('Implementation status change requires a different approver (4-eyes).');
            }
            $this->fourEyesService->requestApproval(
                actionType: \App\Entity\FourEyesApprovalRequest::ACTION_INHERITANCE_IMPLEMENT,
                payload: [
                    'inheritance_log_id' => $log->getId(),
                    'fulfillment_id' => $fulfillment?->getId(),
                    'requested_percentage' => $log->getSuggestedPercentage(),
                ],
                requester: $reviewer,
                specificApprover: $fourEyesApprover,
            );
        }

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'compliance.inheritance.confirmed',
            'FulfillmentInheritanceLog',
            $log->getId(),
            null,
            [
                'fulfillment_id' => $fulfillment?->getId(),
                'reviewer_id' => $reviewer->getId(),
                'percentage' => $log->getSuggestedPercentage(),
                'requested_implemented' => $requestImplementedTransition,
            ],
            'Inheritance suggestion confirmed',
        );
    }

    public function rejectInheritance(
        FulfillmentInheritanceLog $log,
        User $reviewer,
        string $reason,
    ): void {
        $this->assertPending($log);
        $this->assertMinLength($reason, $this->minCommentLength(), 'rejection_reason');

        $log->setReviewStatus(FulfillmentInheritanceLog::STATUS_REJECTED)
            ->setReviewedBy($reviewer)
            ->setReviewedAt(new DateTimeImmutable())
            ->setReviewComment($reason);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'compliance.inheritance.rejected',
            'FulfillmentInheritanceLog',
            $log->getId(),
            null,
            ['reviewer_id' => $reviewer->getId()],
            'Inheritance suggestion rejected',
        );
    }

    public function overrideInheritance(
        FulfillmentInheritanceLog $log,
        User $reviewer,
        int $newValue,
        string $reason,
        ?User $fourEyesApprover = null,
    ): void {
        $this->assertMinLength($reason, $this->minOverrideReasonLength(), 'override_reason');
        if ($newValue < 0 || $newValue > 150) {
            throw new InvalidArgumentException('Override value must be within 0..150.');
        }
        if ($fourEyesApprover === null || $fourEyesApprover->getId() === $reviewer->getId()) {
            throw new InvalidArgumentException('Override requires a different approver (4-eyes).');
        }

        $log->setReviewStatus(FulfillmentInheritanceLog::STATUS_OVERRIDDEN)
            ->setOverriddenBy($reviewer)
            ->setOverriddenAt(new DateTimeImmutable())
            ->setOverrideReason($reason)
            ->setOverrideValue($newValue);

        $fulfillment = $log->getFulfillment();
        if ($fulfillment !== null) {
            $fulfillment->setFulfillmentPercentage(min(100, $newValue));
        }

        $this->fourEyesService->requestApproval(
            actionType: \App\Entity\FourEyesApprovalRequest::ACTION_MAPPING_OVERRIDE,
            payload: [
                'inheritance_log_id' => $log->getId(),
                'override_value' => $newValue,
                'reason' => $reason,
            ],
            requester: $reviewer,
            specificApprover: $fourEyesApprover,
        );

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'compliance.inheritance.overridden',
            'FulfillmentInheritanceLog',
            $log->getId(),
            null,
            [
                'reviewer_id' => $reviewer->getId(),
                'value' => $newValue,
            ],
            'Inheritance suggestion overridden',
        );
    }

    public function markSourceUpdated(FulfillmentInheritanceLog $log): void
    {
        if ($log->getReviewStatus() === FulfillmentInheritanceLog::STATUS_REJECTED) {
            return;
        }
        $log->setReviewStatus(FulfillmentInheritanceLog::STATUS_SOURCE_UPDATED);
    }

    public function getPendingReviewCount(Tenant $tenant, ?ComplianceFramework $framework = null): int
    {
        return $this->inheritanceRepository->countPendingReview($tenant, $framework);
    }

    /**
     * Bulk-confirm a batch of pending suggestions. Same confidence level required,
     * shared reviewer comment (≥20 chars). Items not in pending state are skipped.
     *
     * @param list<FulfillmentInheritanceLog> $logs
     * @return array{confirmed: int, skipped: int}
     */
    public function bulkConfirm(array $logs, User $reviewer, string $comment): array
    {
        $this->assertMinLength($comment, $this->minCommentLength(), 'bulk_review_comment');

        $confidences = [];
        foreach ($logs as $log) {
            $confidences[$log->getDerivedFromMapping()?->getConfidence() ?? 'unknown'] = true;
        }
        if (count($confidences) > 1) {
            throw new InvalidArgumentException('Bulk confirm requires all items to share the same confidence level.');
        }

        $confirmed = 0;
        $skipped = 0;
        $at = new DateTimeImmutable();

        foreach ($logs as $log) {
            if (!$log->isPendingReview()) {
                $skipped++;
                continue;
            }
            $log->setReviewStatus(FulfillmentInheritanceLog::STATUS_CONFIRMED)
                ->setReviewedBy($reviewer)
                ->setReviewedAt($at)
                ->setReviewComment($comment);

            $fulfillment = $log->getFulfillment();
            if ($fulfillment !== null) {
                $fulfillment->setFulfillmentPercentage($log->getSuggestedPercentage());
            }
            $confirmed++;
        }

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'compliance.inheritance.bulk_confirmed',
            'FulfillmentInheritanceLog',
            null,
            null,
            [
                'reviewer_id' => $reviewer->getId(),
                'confirmed' => $confirmed,
                'skipped' => $skipped,
            ],
            sprintf('Bulk-confirmed %d inheritance suggestions (%d skipped)', $confirmed, $skipped),
        );

        return ['confirmed' => $confirmed, 'skipped' => $skipped];
    }

    /**
     * @return list<array{mapping: ComplianceMapping, source_fulfillment: ComplianceRequirementFulfillment, source_percentage: int, mapping_percentage: int, suggested_percentage: int}>
     */
    private function findActiveSourceMappings(
        ComplianceRequirement $target,
        Tenant $tenant,
        DateTimeImmutable $stichtag,
    ): array {
        $mappings = $this->mappingRepository->findBy(['targetRequirement' => $target]);
        $candidates = [];

        foreach ($mappings as $mapping) {
            if (!$mapping->isValidAt($stichtag)) {
                continue;
            }

            $sourceRequirement = $mapping->getSourceRequirement();
            if ($sourceRequirement === null) {
                continue;
            }

            $sourceFulfillment = $this->fulfillmentRepository->findOneBy([
                'tenant' => $tenant,
                'complianceRequirement' => $sourceRequirement,
            ]);
            if (!$sourceFulfillment instanceof ComplianceRequirementFulfillment) {
                continue;
            }
            if (!$sourceFulfillment->isApplicable()) {
                continue;
            }

            $sourcePercent = $sourceFulfillment->getFulfillmentPercentage();
            if ($sourcePercent <= 0) {
                continue;
            }

            $suggested = (int) round(($sourcePercent * $mapping->getMappingPercentage()) / 100);
            if ($suggested <= 0) {
                continue;
            }

            $candidates[] = [
                'mapping' => $mapping,
                'source_fulfillment' => $sourceFulfillment,
                'source_percentage' => $sourcePercent,
                'mapping_percentage' => $mapping->getMappingPercentage(),
                'suggested_percentage' => min(100, $suggested),
            ];
        }

        return $candidates;
    }

    /**
     * @param list<array{mapping: ComplianceMapping, source_fulfillment: ComplianceRequirementFulfillment, source_percentage: int, mapping_percentage: int, suggested_percentage: int}> $candidates
     * @return array{0: ComplianceMapping|null, 1: int}
     */
    private function pickBestCandidate(array $candidates): array
    {
        usort($candidates, function (array $a, array $b): int {
            $confidenceCompare = $this->confidenceWeight($b['mapping']->getConfidence())
                <=> $this->confidenceWeight($a['mapping']->getConfidence());
            if ($confidenceCompare !== 0) {
                return $confidenceCompare;
            }
            return $b['suggested_percentage'] <=> $a['suggested_percentage'];
        });

        $best = $candidates[0] ?? null;
        if ($best === null) {
            return [null, 0];
        }

        return [$best['mapping'], $best['suggested_percentage']];
    }

    private function confidenceWeight(string $confidence): int
    {
        return match ($confidence) {
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    private function assertPending(FulfillmentInheritanceLog $log): void
    {
        if (!$log->isPendingReview()) {
            throw new LogicException('Inheritance log is not in a pending state (status: ' . $log->getReviewStatus() . ').');
        }
    }

    private function assertMinLength(string $value, int $min, string $field): void
    {
        if (mb_strlen(trim($value)) < $min) {
            throw new InvalidArgumentException(sprintf('Field "%s" requires at least %d characters.', $field, $min));
        }
    }
}
