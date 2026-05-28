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
 * TISAX Reifegrad (Maturity) Assessment Service
 *
 * Manages per-tier assessment scoring for tenant-uploaded VDA-ISA requirements.
 *
 * Three distinct tiers with different assessment models (VDA-ISA 6 / ENX workbook):
 *
 *   Chapters 1-6 — information_security:
 *     Reifegrad 0-5 (ISO/IEC 33020 process capability scale).
 *     Stored in ComplianceRequirement.maturityCurrent (string).
 *
 *   Chapters 7-8 — prototype_protection:
 *     Same Reifegrad 0-5 scale as IS.
 *     Stored in ComplianceRequirement.maturityCurrent (string).
 *
 *   Chapter 9 — data_protection:
 *     Tristate GDPR-conformance assessment — NOT Reifegrad 0-5.
 *     Values: not_applicable (NA) | compliant (OK) | non_compliant (Nicht OK)
 *     Stored in ComplianceRequirement.assessmentStateDp (string).
 */
final class TisaxMaturityAssessmentService
{
    /** Valid Reifegrad levels — IS and PP tiers only */
    public const REIFEGRAD_LEVELS = [0, 1, 2, 3, 4, 5];

    /** Valid tristate states for the Data Protection tier */
    public const DP_STATES = ['not_applicable', 'compliant', 'non_compliant'];

    /** Categories that use the Reifegrad 0-5 model */
    public const REIFEGRAD_CATEGORIES = ['information_security', 'prototype_protection'];

    /** Reifegrad level → ComplianceRequirement maturity string mapping */
    public const LEVEL_MAP = [
        0 => 'incomplete',
        1 => 'performed',
        2 => 'managed',
        3 => 'established',
        4 => 'predictable',
        5 => 'optimising',
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
     * Bulk-set Data Protection tristate compliance for DP-tier requirements.
     *
     * Tenant-scoped: silently skips requirements whose uploadTenant does not
     * match $tenant (cross-tenant write guard).
     *
     * Only applies to requirements with category = 'data_protection'. Any
     * requirement submitted with a different category is silently skipped.
     *
     * @param array<int, string> $valueMap  requirementId (int PK) => DP state string
     * @return int  number of requirements actually updated
     */
    public function bulkSetDataProtectionCompliance(
        array $valueMap,
        User $reviewer,
        Tenant $tenant,
    ): int {
        $repo    = $this->em->getRepository(ComplianceRequirement::class);
        $updated = 0;

        foreach ($valueMap as $requirementId => $state) {
            /** @var ComplianceRequirement|null $req */
            $req = $repo->find($requirementId);
            if ($req === null) {
                continue;
            }
            // Cross-tenant guard
            if ($req->getUploadTenant() === null || $req->getUploadTenant()->getId() !== $tenant->getId()) {
                continue;
            }
            // Only DP-tier requirements use the tristate model
            if ($req->getCategory() !== 'data_protection') {
                continue;
            }
            if (!in_array($state, self::DP_STATES, true)) {
                continue;
            }
            $req->setAssessmentStateDp($state);
            $req->setUpdatedAt(new DateTimeImmutable());
            $updated++;
        }

        if ($updated > 0) {
            $this->em->flush();
        }

        return $updated;
    }

    /**
     * Dispatcher: route assessment values to the correct setter by tier.
     *
     * IS/PP tier requirements receive Reifegrad int values (0-5) and are
     * routed to bulkSetReifegrad(). DP tier requirements receive tristate
     * string values and are routed to bulkSetDataProtectionCompliance().
     *
     * Mismatched values (Reifegrad int on a DP requirement, or tristate
     * string on an IS/PP requirement) are silently rejected — the caller
     * must send the correct type for the tier.
     *
     * This method loads each requirement once and branches internally to
     * avoid redundant DB round-trips on mixed-tier submissions.
     *
     * @param array<int, int|string> $valueMap  requirementId => Reifegrad int OR DP string
     * @return array{reifegrad: int, data_protection: int, rejected: int}
     */
    public function bulkSetAssessment(
        array $valueMap,
        User $reviewer,
        Tenant $tenant,
    ): array {
        $repo = $this->em->getRepository(ComplianceRequirement::class);

        $reifegradMap = [];
        $dpMap        = [];
        $rejected     = 0;

        foreach ($valueMap as $requirementId => $value) {
            /** @var ComplianceRequirement|null $req */
            $req = $repo->find($requirementId);
            if ($req === null) {
                $rejected++;
                continue;
            }
            // Cross-tenant guard
            if ($req->getUploadTenant() === null || $req->getUploadTenant()->getId() !== $tenant->getId()) {
                $rejected++;
                continue;
            }

            $category = $req->getCategory() ?? 'information_security';

            if (in_array($category, self::REIFEGRAD_CATEGORIES, true)) {
                // IS or PP tier — value must be int 0-5
                if (!is_int($value) && !ctype_digit((string) $value)) {
                    $rejected++;
                    continue;
                }
                $intVal = (int) $value;
                if (!in_array($intVal, self::REIFEGRAD_LEVELS, true)) {
                    $rejected++;
                    continue;
                }
                $reifegradMap[$requirementId] = $intVal;
            } elseif ($category === 'data_protection') {
                // DP tier — value must be a DP_STATES string
                if (!is_string($value) || !in_array($value, self::DP_STATES, true)) {
                    $rejected++;
                    continue;
                }
                $dpMap[$requirementId] = $value;
            } else {
                // Unknown category — skip
                $rejected++;
            }
        }

        $reifegradCount = $this->bulkSetReifegrad($reifegradMap, $reviewer, $tenant);
        $dpCount        = $this->bulkSetDataProtectionCompliance($dpMap, $reviewer, $tenant);

        return [
            'reifegrad'        => $reifegradCount,
            'data_protection'  => $dpCount,
            'rejected'         => $rejected,
        ];
    }

    /**
     * Calculate aggregate assessment data for a framework + tenant.
     *
     * IS/PP tiers: Reifegrad arithmetic mean.
     * DP tier: tristate count breakdown (compliant / non_compliant / not_applicable).
     *
     * Returns an array with:
     *   - average:       float  IS/PP arithmetic mean (0.0 when none)
     *   - assessed:      int    IS/PP rows with maturityCurrent set
     *   - total:         int    all tenant-upload rows (all tiers)
     *   - byTier:        array  tier => tier-specific aggregate (see below)
     *   - distribution:  array  level (0-5) => count (IS/PP only)
     *   - dp:            array  DP rollup: compliant/non_compliant/not_applicable/total/percent_compliant
     *
     * byTier format:
     *   IS/PP: ['average' => float, 'count' => int, 'model' => 'reifegrad']
     *   DP:    ['compliant' => int, 'non_compliant' => int, 'not_applicable' => int,
     *            'total' => int, 'percent_compliant' => float, 'model' => 'tristate']
     *
     * @return array<string, mixed>
     */
    public function computeAggregate(ComplianceFramework $framework, Tenant $tenant): array
    {
        $repo = $this->em->getRepository(ComplianceRequirement::class);

        /** @var list<ComplianceRequirement> $rows */
        $rows = $repo->findBy([
            'framework'         => $framework,
            'uploadTenant'      => $tenant,
            'requirementSource' => 'tenant_upload',
        ]);

        $total      = count($rows);
        $levelToInt = array_flip(self::LEVEL_MAP); // 'incomplete' => 0, etc.

        $sum          = 0;
        $assessed     = 0;
        $distribution = array_fill(0, 6, 0);
        $tierData     = [];

        // DP tier counters
        $dpCompliant      = 0;
        $dpNonCompliant   = 0;
        $dpNotApplicable  = 0;
        $dpTotal          = 0;

        foreach ($rows as $req) {
            $category = $req->getCategory() ?? 'information_security';

            if ($category === 'data_protection') {
                $dpTotal++;
                $state = $req->getAssessmentStateDp();
                match ($state) {
                    'compliant'      => $dpCompliant++,
                    'non_compliant'  => $dpNonCompliant++,
                    'not_applicable' => $dpNotApplicable++,
                    default          => null,
                };
                // Track in byTier with tristate model
                $tierData['data_protection']['compliant']       = ($tierData['data_protection']['compliant'] ?? 0) + ($state === 'compliant' ? 1 : 0);
                $tierData['data_protection']['non_compliant']   = ($tierData['data_protection']['non_compliant'] ?? 0) + ($state === 'non_compliant' ? 1 : 0);
                $tierData['data_protection']['not_applicable']  = ($tierData['data_protection']['not_applicable'] ?? 0) + ($state === 'not_applicable' ? 1 : 0);
                $tierData['data_protection']['total']           = ($tierData['data_protection']['total'] ?? 0) + 1;
                $tierData['data_protection']['model']           = 'tristate';
                continue;
            }

            // IS or PP tier — Reifegrad 0-5
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

            $tierData[$category]['sum']   = ($tierData[$category]['sum'] ?? 0) + $level;
            $tierData[$category]['count'] = ($tierData[$category]['count'] ?? 0) + 1;
            $tierData[$category]['model'] = 'reifegrad';
        }

        $byTier = [];
        foreach ($tierData as $tier => $data) {
            if (($data['model'] ?? 'reifegrad') === 'tristate') {
                $t = $data['total'] ?? 0;
                $c = $data['compliant'] ?? 0;
                $byTier[$tier] = [
                    'compliant'       => $c,
                    'non_compliant'   => $data['non_compliant'] ?? 0,
                    'not_applicable'  => $data['not_applicable'] ?? 0,
                    'total'           => $t,
                    'percent_compliant' => $t > 0 ? round($c / $t * 100, 1) : 0.0,
                    'model'           => 'tristate',
                ];
            } else {
                $cnt = $data['count'] ?? 0;
                $byTier[$tier] = [
                    'average' => $cnt > 0 ? round($data['sum'] / $cnt, 2) : 0.0,
                    'count'   => $cnt,
                    'model'   => 'reifegrad',
                ];
            }
        }

        $applicableForDp = $dpTotal - $dpNotApplicable;

        return [
            'average'      => $assessed > 0 ? round($sum / $assessed, 2) : 0.0,
            'assessed'     => $assessed,
            'total'        => $total,
            'byTier'       => $byTier,
            'distribution' => $distribution,
            'dp'           => [
                'compliant'       => $dpCompliant,
                'non_compliant'   => $dpNonCompliant,
                'not_applicable'  => $dpNotApplicable,
                'total'           => $dpTotal,
                'percent_compliant' => $applicableForDp > 0 ? round($dpCompliant / $applicableForDp * 100, 1) : 0.0,
            ],
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
