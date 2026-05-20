<?php

declare(strict_types=1);

namespace App\Service\DataIntegrity;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Detects status-column values that are no longer present in the corresponding
 * PHP 8.1 BackedEnum definition (status-enum drift).
 *
 * For each entity/enum pair the checker queries the DISTINCT values actually
 * stored in the `status` column and reports any value not covered by the enum's
 * case-set.
 *
 * Detection-only. Repair is a manual triage decision (rename/migrate legacy
 * values via a one-off SQL or a dedicated console command) — automated repair
 * would risk hiding a real lifecycle bug.
 *
 * Extracted from DataIntegrityService to isolate enum-drift concerns.
 *
 * @see \App\Service\DataIntegrityService::findStatusEnumDriftIssues()
 */
final class StatusEnumDriftChecker
{
    /**
     * Entity→Enum pairs that are checked on every run.
     *
     * The mapping is intentionally explicit (rather than guessing entity-FQCN
     * from enum-FQCN) because not every *Status enum is paired with the
     * identically-named entity (e.g. BCExerciseStatus → BCExercise).
     *
     * @var array<class-string, class-string>
     */
    private const ENTITY_ENUM_PAIRS = [
        \App\Entity\Asset::class                       => \App\Enum\AssetStatus::class,
        \App\Entity\Risk::class                        => \App\Enum\RiskStatus::class,
        \App\Entity\Incident::class                    => \App\Enum\IncidentStatus::class,
        \App\Entity\Document::class                    => \App\Enum\DocumentStatus::class,
        \App\Entity\InternalAudit::class               => \App\Enum\InternalAuditStatus::class,
        \App\Entity\AuditFinding::class                => \App\Enum\AuditFindingStatus::class,
        \App\Entity\CorrectiveAction::class            => \App\Enum\CorrectiveActionStatus::class,
        \App\Entity\BusinessContinuityPlan::class      => \App\Enum\BusinessContinuityPlanStatus::class,
        \App\Entity\DataBreach::class                  => \App\Enum\DataBreachStatus::class,
        \App\Entity\ProcessingActivity::class          => \App\Enum\ProcessingActivityStatus::class,
        \App\Entity\Supplier::class                    => \App\Enum\SupplierStatus::class,
        \App\Entity\RiskTreatmentPlan::class           => \App\Enum\RiskTreatmentPlanStatus::class,
        \App\Entity\SsoUserApproval::class             => \App\Enum\SsoUserApprovalStatus::class,
        \App\Entity\EvidenceReverificationTask::class  => \App\Enum\EvidenceReverificationTaskStatus::class,
        \App\Entity\ChangeRequest::class               => \App\Enum\ChangeRequestStatus::class,
        \App\Entity\ManagementReview::class            => \App\Enum\ManagementReviewStatus::class,
        \App\Entity\Training::class                    => \App\Enum\TrainingStatus::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Scans every registered entity/enum pair and reports drift rows.
     *
     * @return list<array{
     *     entity: string,
     *     enum: string,
     *     unknown_values: array<string, int>,
     * }>
     */
    public function findDriftIssues(): array
    {
        $result = [];
        $factory = $this->entityManager->getMetadataFactory();

        foreach (self::ENTITY_ENUM_PAIRS as $entityFqcn => $enumFqcn) {
            if (!class_exists($entityFqcn) || !enum_exists($enumFqcn)) {
                continue;
            }
            try {
                $metadata = $factory->getMetadataFor($entityFqcn);
            } catch (\Throwable) {
                continue;
            }
            if (!$metadata->hasField('status')) {
                continue;
            }

            $allowed = [];
            foreach ($enumFqcn::cases() as $case) {
                $allowed[(string) $case->value] = true;
            }

            try {
                $rows = $this->entityManager->createQueryBuilder()
                    ->select('e.status AS status, COUNT(e.id) AS cnt')
                    ->from($entityFqcn, 'e')
                    ->where('e.status IS NOT NULL')
                    ->groupBy('e.status')
                    ->getQuery()
                    ->getArrayResult();
            } catch (\Throwable) {
                continue;
            }

            $unknown = [];
            foreach ($rows as $row) {
                $raw = $row['status'] ?? null;
                // Doctrine returns BackedEnum for entities with enumType mapping.
                $value = $raw instanceof \BackedEnum ? $raw->value : (string) ($raw ?? '');
                if ($value === '' || isset($allowed[$value])) {
                    continue;
                }
                $unknown[$value] = (int) ($row['cnt'] ?? 0);
            }

            if ($unknown !== []) {
                $shortEntity = substr($entityFqcn, strrpos($entityFqcn, '\\') + 1);
                $shortEnum = substr($enumFqcn, strrpos($enumFqcn, '\\') + 1);
                $result[] = [
                    'entity' => $shortEntity,
                    'enum' => $shortEnum,
                    'unknown_values' => $unknown,
                ];
            }
        }

        return $result;
    }

    /**
     * Convenience accessor: returns the full ENTITY_ENUM_PAIRS map.
     * Used by console commands that need to iterate the pairs independently.
     *
     * @return array<class-string, class-string>
     */
    public function getEntityEnumPairs(): array
    {
        return self::ENTITY_ENUM_PAIRS;
    }
}
