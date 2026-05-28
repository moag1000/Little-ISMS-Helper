<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TISAX Assessment Service — supports all three VDA-ISA tier models.
 *
 * VDA-ISA v6.0 uses three distinct assessment models per tier:
 *
 * Tier 1 — Information Security (Chapters 1-6):
 *   ISO/IEC 33020 Process Capability levels 0-5 (Reifegrad).
 *   Stored in ComplianceRequirement.maturityCurrent (string code).
 *
 * Tier 2 — Prototype Protection (Chapters 7-8):
 *   Binary compliance: 'compliant' | 'not_compliant' | 'na'.
 *   Stored in ComplianceRequirement.assessmentValue.
 *
 * Tier 3 — Data Protection (Chapter 9):
 *   GDPR Art. 28 conformance: 'in_place' | 'partial' | 'not_in_place' | 'na'.
 *   Stored in ComplianceRequirement.assessmentValue.
 */
final class TisaxMaturityAssessmentService
{
    /** Valid Reifegrad levels — Tier 1 (Information Security) only */
    public const REIFEGRAD_LEVELS = [0, 1, 2, 3, 4, 5];

    /** Reifegrad level → ComplianceRequirement maturity string mapping (Tier 1) */
    public const LEVEL_MAP = [
        0 => 'incomplete',
        1 => 'performed',
        2 => 'managed',
        3 => 'established',
        4 => 'predictable',
        5 => 'optimising',
    ];

    /**
     * Valid values per assessment model.
     *
     * Tier 2 (prototype_protection) — binary compliance.
     * Tier 3 (data_protection) — GDPR Art. 28 conformance.
     */
    public const BINARY_COMPLIANCE_VALUES = ['compliant', 'not_compliant', 'na'];
    public const GDPR_CONFORMANCE_VALUES  = ['in_place', 'partial', 'not_in_place', 'na'];

    /**
     * Category code → assessment model name.
     *
     * Mirrors the assessmentModels block in vda-isa-tisax-v6.yaml.
     * Public so importers and controllers can resolve models without
     * instantiating the service.
     */
    public const CATEGORY_MODEL_MAP = [
        'information_security' => 'information_security',
        'prototype_protection' => 'prototype_protection',
        'data_protection'      => 'data_protection',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Set the current Reifegrad for a single requirement.
     *
     * @throws \InvalidArgumentException on invalid level
     */
    public function setReifegrad(
        ComplianceRequirement $requirement,
        int $level,
        User $reviewer,
    ): void {
        if (!in_array($level, self::REIFEGRAD_LEVELS, true)) {
            // @intentional-assertion: programmer-error guard — invalid Reifegrad level is a caller contract violation
            throw new \InvalidArgumentException(
                sprintf('Invalid Reifegrad level %d. Must be 0–5.', $level),
            );
        }

        $requirement->setMaturityCurrent(self::LEVEL_MAP[$level]);
        $requirement->setMaturityReviewedAt(new DateTimeImmutable());
        $requirement->setUpdatedAt(new DateTimeImmutable());

        $this->em->flush();
    }

    /**
     * Bulk-set Reifegrad for all tenant-uploaded requirements of a framework.
     *
     * Tenant-scoped: silently skips requirements whose uploadTenant does not
     * match the supplied $tenant. This prevents a cross-tenant write where a
     * ROLE_MANAGER in tenant A submits requirement IDs belonging to tenant B
     * in the POST body and overwrites their maturityCurrent.
     *
     * @param array<int, int> $levelMap  requirementId (int PK) => Reifegrad level
     */
    public function bulkSetReifegrad(
        array $levelMap,
        User $reviewer,
        Tenant $tenant,
    ): int {
        $repo    = $this->em->getRepository(ComplianceRequirement::class);
        $updated = 0;

        foreach ($levelMap as $requirementId => $level) {
            /** @var ComplianceRequirement|null $req */
            $req = $repo->find($requirementId);
            if ($req === null) {
                continue;
            }
            // Cross-tenant guard — uploadTenant MUST match the caller's tenant.
            // System-seeded rows (uploadTenant=NULL) are also rejected here:
            // bulkSetReifegrad is intended for tenant-uploaded requirements only.
            if ($req->getUploadTenant() === null || $req->getUploadTenant()->getId() !== $tenant->getId()) {
                continue;
            }
            if (!in_array($level, self::REIFEGRAD_LEVELS, true)) {
                continue;
            }
            $req->setMaturityCurrent(self::LEVEL_MAP[$level]);
            $req->setMaturityReviewedAt(new DateTimeImmutable());
            $req->setUpdatedAt(new DateTimeImmutable());
            $updated++;
        }

        if ($updated > 0) {
            $this->em->flush();
        }

        return $updated;
    }

    /**
     * Return the assessment model name for a given category code.
     *
     * Falls back to 'information_security' for unknown categories so that
     * legacy system rows (no category set) keep working with the Reifegrad model.
     */
    public function getAssessmentModelForCategory(string $categoryCode): string
    {
        return self::CATEGORY_MODEL_MAP[$categoryCode] ?? 'information_security';
    }

    /**
     * Bulk-set compliance value for Tier 2 or Tier 3 requirements.
     *
     * Generic dispatcher: routes to the correct storage field based on the
     * assessment model of each requirement's category.
     *
     * Tier 1 (information_security):  stores in maturityCurrent (int-mapped string)
     * Tier 2 (prototype_protection):  stores in assessmentValue ('compliant'|'not_compliant'|'na')
     * Tier 3 (data_protection):       stores in assessmentValue ('in_place'|'partial'|'not_in_place'|'na')
     *
     * Cross-model values are rejected (e.g. submitting '3' for a PP-tier requirement
     * or 'compliant' for an IS-tier requirement).
     *
     * Tenant-scoped: silently skips requirements whose uploadTenant differs from $tenant.
     *
     * @param array<int, string> $valueMap  requirementId (int PK) => assessment value string
     * @return int number of requirements updated
     */
    public function bulkSetCompliance(
        array $valueMap,
        User $reviewer,
        Tenant $tenant,
        string $assessmentModel,
    ): int {
        $allowedValues = match ($assessmentModel) {
            'information_security' => array_values(self::LEVEL_MAP),
            'prototype_protection' => self::BINARY_COMPLIANCE_VALUES,
            'data_protection'      => self::GDPR_CONFORMANCE_VALUES,
            default => throw new \InvalidArgumentException(
                sprintf('Unknown assessment model "%s".', $assessmentModel),
            ),
        };

        $repo    = $this->em->getRepository(ComplianceRequirement::class);
        $updated = 0;

        foreach ($valueMap as $requirementId => $value) {
            /** @var ComplianceRequirement|null $req */
            $req = $repo->find($requirementId);
            if ($req === null) {
                continue;
            }
            // Cross-tenant guard
            if ($req->getUploadTenant() === null || $req->getUploadTenant()->getId() !== $tenant->getId()) {
                continue;
            }
            // Cross-model guard — reject if value is not in the model's allowed set
            if (!in_array($value, $allowedValues, true)) {
                continue;
            }
            // Category-model consistency guard — the requirement's own category must match
            $reqModel = $this->getAssessmentModelForCategory($req->getCategory() ?? 'information_security');
            if ($reqModel !== $assessmentModel) {
                continue;
            }

            if ($assessmentModel === 'information_security') {
                // IS-tier: store in maturityCurrent (existing field)
                $req->setMaturityCurrent($value);
                $req->setMaturityReviewedAt(new DateTimeImmutable());
            } else {
                // PP/DP-tier: store in assessmentValue (new field)
                $req->setAssessmentValue($value);
                $req->setMaturityReviewedAt(new DateTimeImmutable());
            }

            $req->setUpdatedAt(new DateTimeImmutable());
            $updated++;
        }

        if ($updated > 0) {
            $this->em->flush();
        }

        return $updated;
    }

    /**
     * Calculate aggregate Reifegrad score for a framework + tenant.
     *
     * Returns an array with:
     *   - average:       float  (arithmetic mean of IS-tier Reifegrad rows, 0.0 when none)
     *   - assessed:      int    (IS-tier rows with maturityCurrent set)
     *   - total:         int    (all tenant-upload rows for this framework)
     *   - byTier:        array  tier → tier-specific aggregate (see below)
     *     For 'information_security': ['average' => float, 'count' => int]
     *     For 'prototype_protection': ['compliant' => int, 'total' => int]
     *     For 'data_protection':      ['in_place' => int, 'total' => int]
     *   - distribution:  array  level (0-5) => count  (IS-tier only)
     *
     * @return array{average: float, assessed: int, total: int, byTier: array<string, mixed>, distribution: array<int, int>}
     */
    public function computeAggregate(ComplianceFramework $framework, Tenant $tenant): array
    {
        $repo = $this->em->getRepository(ComplianceRequirement::class);

        /** @var list<ComplianceRequirement> $rows */
        $rows = $repo->findBy([
            'framework'        => $framework,
            'uploadTenant'     => $tenant,
            'requirementSource' => 'tenant_upload',
        ]);

        $total   = count($rows);
        $levelToInt = array_flip(self::LEVEL_MAP); // 'incomplete' => 0, etc.

        $sum          = 0;
        $assessed     = 0;
        $distribution = array_fill(0, 6, 0);
        $tierData     = [];

        foreach ($rows as $req) {
            $tier  = $req->getCategory() ?? 'information_security';
            $model = $this->getAssessmentModelForCategory($tier);

            if ($model === 'information_security') {
                // IS-tier: numeric Reifegrad from maturityCurrent
                $current = $req->getMaturityCurrent();
                if ($current === null) {
                    continue;
                }
                $level = $levelToInt[$current] ?? null;
                if ($level === null) {
                    // Handle numeric strings stored before the enum migration
                    $level = is_numeric($current) ? (int) $current : null;
                }
                if ($level === null) {
                    continue;
                }

                $sum += $level;
                $assessed++;
                $distribution[$level]++;

                $tierData[$tier]['sum']   = ($tierData[$tier]['sum'] ?? 0) + $level;
                $tierData[$tier]['count'] = ($tierData[$tier]['count'] ?? 0) + 1;
            } else {
                // PP/DP-tier: binary/conformance from assessmentValue
                $value = $req->getAssessmentValue();
                if ($value === null) {
                    continue;
                }
                $tierData[$tier]['total'] = ($tierData[$tier]['total'] ?? 0) + 1;
                if ($model === 'prototype_protection' && $value === 'compliant') {
                    $tierData[$tier]['compliant'] = ($tierData[$tier]['compliant'] ?? 0) + 1;
                } elseif ($model === 'data_protection' && $value === 'in_place') {
                    $tierData[$tier]['in_place'] = ($tierData[$tier]['in_place'] ?? 0) + 1;
                }
            }
        }

        $byTier = [];
        foreach ($tierData as $tier => $data) {
            $model = $this->getAssessmentModelForCategory($tier);
            if ($model === 'information_security') {
                $byTier[$tier] = [
                    'average' => ($data['count'] ?? 0) > 0 ? round($data['sum'] / $data['count'], 2) : 0.0,
                    'count'   => $data['count'] ?? 0,
                    'model'   => 'information_security',
                ];
            } elseif ($model === 'prototype_protection') {
                $byTier[$tier] = [
                    'compliant' => $data['compliant'] ?? 0,
                    'total'     => $data['total'] ?? 0,
                    'model'     => 'prototype_protection',
                ];
            } else {
                $byTier[$tier] = [
                    'in_place' => $data['in_place'] ?? 0,
                    'total'    => $data['total'] ?? 0,
                    'model'    => 'data_protection',
                ];
            }
        }

        return [
            'average'      => $assessed > 0 ? round($sum / $assessed, 2) : 0.0,
            'assessed'     => $assessed,
            'total'        => $total,
            'byTier'       => $byTier,
            'distribution' => $distribution,
        ];
    }

    /**
     * Return the integer level for a maturity string, or null if unmapped.
     */
    public static function levelForString(string $maturityString): ?int
    {
        $flip = array_flip(self::LEVEL_MAP);
        return $flip[$maturityString] ?? null;
    }
}
