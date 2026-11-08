<?php

declare(strict_types=1);

namespace App\Service\Review;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Enum\SupplierStatus;
use App\Security\Voter\RecordResponsibleVoter;
use App\Service\AuditLogger;
use App\Service\RiskReviewService;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The one definition of the periodic review of Risk, Control, Document and
 * Supplier: which records take part, when one is due, which date the next
 * review gets, and how a review is confirmed (singly or in bulk from the
 * list bulk bar, "Review bestätigen").
 *
 * Rules per type (existing schedules, nothing new invented):
 *  - Risk (ISO 27001 Cl. 6.1.3/8.2): all statuses except closed; next date
 *    from {@see RiskReviewService::getReviewIntervalDays()} — the risk's own
 *    interval, otherwise 90/180/365/730 days by risk level.
 *  - Control (ISO 27001 Cl. 9.1): applicable controls; last review = today,
 *    next = today + the cadence the control had so far (next − last), else
 *    12 months.
 *  - Document (ISO 27001 Cl. 7.5.3): approved or published documents; next
 *    = today + reviewIntervalMonths (same rule as DocumentApprovalListener).
 *  - Supplier (ISO 27001 A.5.22): evaluation or active suppliers; last
 *    security assessment = today, next = today + previous cadence, else
 *    12 months (the 12-month rule of the supplier KPIs and Alva hints).
 *
 * "Due" means: the next date has passed or no review was ever recorded
 * (suppliers: never assessed counts only for active ones, as in
 * the overdue count of SupplierRepository::getStatisticsByTenant()).
 */
final class PeriodicReviewService
{
    private const int DEFAULT_CADENCE_MONTHS = 12;

    public function __construct(
        private readonly RiskReviewService $riskReviewService,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public static function supports(object $entity): bool
    {
        return $entity instanceof Risk || $entity instanceof Control
            || $entity instanceof Document || $entity instanceof Supplier;
    }

    /**
     * Whether the record takes part in the periodic review at all.
     */
    public function isReviewable(object $entity): bool
    {
        return match (true) {
            $entity instanceof Risk => $entity->getStatus()?->requiresPeriodicReview() !== false,
            $entity instanceof Control => $entity->isApplicable() !== false,
            $entity instanceof Document => !$entity->isArchived()
                && in_array($entity->getStatus(), ['approved', 'published'], true),
            $entity instanceof Supplier => in_array(
                $entity->getStatusEnum(),
                [SupplierStatus::Active, SupplierStatus::Evaluation],
                true,
            ),
            default => false,
        };
    }

    public function isDue(object $entity, ?DateTimeInterface $today = null): bool
    {
        if (!$this->isReviewable($entity)) {
            return false;
        }
        $today ??= new DateTime('today');
        $next = $this->nextDateOf($entity);
        $neverReviewed = match (true) {
            $entity instanceof Control => $entity->getLastReviewDate() === null,
            // Same rule as the overdue count of SupplierRepository::getStatisticsByTenant(): a supplier
            // still in evaluation is not overdue just because it was never assessed.
            $entity instanceof Supplier => $entity->getLastSecurityAssessment() === null
                && $entity->getStatusEnum() === SupplierStatus::Active,
            default => $next === null,
        };

        return $neverReviewed || ($next !== null && $next < $today);
    }

    /**
     * @template T of object
     * @param iterable<T> $entities
     * @return list<T>
     */
    public function filterDue(iterable $entities): array
    {
        $today = new DateTime('today');
        $due = [];
        foreach ($entities as $entity) {
            if ($this->isDue($entity, $today)) {
                $due[] = $entity;
            }
        }

        return $due;
    }

    /**
     * The list filter `?review_overdue=1` of the four lists: `$active` false
     * returns the list untouched.
     *
     * @template T of object
     * @param array<T> $entities
     * @return array<T>
     */
    public function filterDueWhen(bool $active, array $entities): array
    {
        return $active ? $this->filterDue($entities) : $entities;
    }

    /**
     * The next review date a confirmation today would set.
     */
    public function nextReviewDate(object $entity, ?DateTime $today = null): DateTime
    {
        $today = $today !== null ? clone $today : new DateTime('today');

        return match (true) {
            $entity instanceof Risk => $today->modify(
                sprintf('+%d days', $this->riskReviewService->getReviewIntervalDays($entity)),
            ),
            $entity instanceof Document => $today->modify(
                sprintf('+%d months', max(1, $entity->getReviewIntervalMonths())),
            ),
            $entity instanceof Control => $this->keepCadence(
                $today,
                $entity->getLastReviewDate(),
                $entity->getNextReviewDate(),
            ),
            $entity instanceof Supplier => $this->keepCadence(
                $today,
                $entity->getLastSecurityAssessment(),
                $entity->getNextAssessmentDate(),
            ),
            // @intentional-assertion: callers pass only the four supported types (see supports())
            default => throw new \InvalidArgumentException(sprintf('No periodic review for %s', $entity::class)),
        };
    }

    /**
     * Confirm the review of the given records in one transaction with one
     * AuditLogger::logBulk() batch (ISO 27001 Cl. 7.5.3). The caller has
     * already checked the tenant scope; this checks who may confirm each record
     * ({@see mayConfirm()}) and whether it takes part in the review.
     *
     * @param list<object> $entities
     * @param list<array{id: int|null, reason: string}> $rejected Rejections collected by the caller.
     * @return array{ok: bool, changed: int, rejected: list<array{id: int|null, reason: string}>, batch_id: ?string}
     */
    public function confirm(string $entityType, array $entities, array $rejected = []): array
    {
        $today = new DateTime('today');
        $perEntity = [];
        $batchId = null;

        $this->entityManager->beginTransaction();
        try {
            foreach ($entities as $entity) {
                $id = method_exists($entity, 'getId') ? $entity->getId() : null;
                if (!$this->mayConfirm($entity)) {
                    $rejected[] = ['id' => $id, 'reason' => 'access_denied'];
                    continue;
                }
                if (!self::supports($entity) || !$this->isReviewable($entity)) {
                    $rejected[] = ['id' => $id, 'reason' => 'not_reviewable'];
                    continue;
                }
                $perEntity[] = ['action' => 'update', 'entity_id' => $id] + $this->apply($entity, $today);
            }

            if ($perEntity !== []) {
                $this->entityManager->flush();
                $batchId = $this->auditLogger->logBulk(
                    eventType: 'bulk_review_confirm',
                    entityType: $entityType,
                    batchData: [
                        'requested' => count($perEntity) + count($rejected),
                        'confirmed' => count($perEntity),
                        'rejected' => count($rejected),
                        'review_date' => $today->format('Y-m-d'),
                    ],
                    perEntityData: $perEntity,
                    description: sprintf('Periodic review confirmed for %d %s records', count($perEntity), $entityType),
                    strict: true,
                );
            }
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            throw $e;
        }

        return ['ok' => true, 'changed' => count($perEntity), 'rejected' => $rejected, 'batch_id' => $batchId];
    }

    /**
     * Risk, Control: the `edit` voter (managers and owners). Document, Supplier:
     * managers, or whoever the review reminder reaches (RECORD_RESPONSIBLE —
     * the document owner, the supplier's contact), so My Day's "in Liste
     * prüfen" works for them too.
     */
    private function mayConfirm(object $entity): bool
    {
        if ($entity instanceof Risk || $entity instanceof Control) {
            return $this->authorizationChecker->isGranted('edit', $entity);
        }

        return $this->authorizationChecker->isGranted('ROLE_MANAGER')
            || $this->authorizationChecker->isGranted(RecordResponsibleVoter::RESPONSIBLE, $entity);
    }

    /**
     * Set the review dates of one record (no flush).
     *
     * @return array{old_values: array<string, ?string>, new_values: array<string, ?string>}
     */
    private function apply(object $entity, DateTime $today): array
    {
        $next = $this->nextReviewDate($entity, $today);
        $fmt = static fn (?DateTimeInterface $d): ?string => $d?->format('Y-m-d');

        switch (true) {
            case $entity instanceof Risk:
                $old = ['review_date' => $fmt($entity->getReviewDate())];
                $entity->setReviewDate($next);
                $new = ['reviewed_on' => $fmt($today), 'review_date' => $fmt($next)];
                break;
            case $entity instanceof Document:
                $old = ['next_review_date' => $fmt($entity->getNextReviewDate())];
                $entity->setNextReviewDate($next);
                $new = ['reviewed_on' => $fmt($today), 'next_review_date' => $fmt($next)];
                break;
            case $entity instanceof Control:
                $old = [
                    'last_review_date' => $fmt($entity->getLastReviewDate()),
                    'next_review_date' => $fmt($entity->getNextReviewDate()),
                ];
                $entity->setLastReviewDate(clone $today);
                $entity->setNextReviewDate($next);
                $new = ['last_review_date' => $fmt($today), 'next_review_date' => $fmt($next)];
                break;
            case $entity instanceof Supplier:
                $old = [
                    'last_security_assessment' => $fmt($entity->getLastSecurityAssessment()),
                    'next_assessment_date' => $fmt($entity->getNextAssessmentDate()),
                ];
                $entity->setLastSecurityAssessment(clone $today);
                $entity->setNextAssessmentDate($next);
                $new = ['last_security_assessment' => $fmt($today), 'next_assessment_date' => $fmt($next)];
                break;
            default:
                // @intentional-assertion: confirm() filters unsupported types before apply()
                throw new \InvalidArgumentException(sprintf('No periodic review for %s', $entity::class));
        }

        return ['old_values' => $old, 'new_values' => $new];
    }

    private function nextDateOf(object $entity): ?DateTimeInterface
    {
        return match (true) {
            $entity instanceof Risk => $entity->getReviewDate(),
            $entity instanceof Control, $entity instanceof Document => $entity->getNextReviewDate(),
            $entity instanceof Supplier => $entity->getNextAssessmentDate(),
            default => null,
        };
    }

    /**
     * today + (next − last) when the record has a cadence, else 12 months.
     */
    private function keepCadence(DateTime $today, ?DateTimeInterface $last, ?DateTimeInterface $next): DateTime
    {
        if ($last !== null && $next !== null && $next > $last) {
            $days = (int) $last->diff($next)->days;
            if ($days > 0) {
                return $today->modify(sprintf('+%d days', $days));
            }
        }

        return $today->modify(sprintf('+%d months', self::DEFAULT_CADENCE_MONTHS));
    }
}
