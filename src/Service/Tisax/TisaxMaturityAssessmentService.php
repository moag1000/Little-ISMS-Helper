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
 * Manages Reifegrad 0-5 scoring for tenant-uploaded VDA-ISA requirements.
 *
 * Reifegrad scale (aligned with VDA-ISA / Automotive SPICE):
 *   0 — Incomplete / not implemented
 *   1 — Performed (ad-hoc, not documented)
 *   2 — Managed (planned and tracked)
 *   3 — Established (standardised process)
 *   4 — Predictable (measured and controlled)
 *   5 — Optimising (continuously improving)
 *
 * The service stores the score in ComplianceRequirement.maturityCurrent
 * (string → cast) with the target in maturityTarget.
 */
final class TisaxMaturityAssessmentService
{
    /** Valid Reifegrad levels */
    public const REIFEGRAD_LEVELS = [0, 1, 2, 3, 4, 5];

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
     * @param array<int, int> $levelMap  requirementId (int PK) => Reifegrad level
     */
    public function bulkSetReifegrad(
        array $levelMap,
        User $reviewer,
    ): int {
        $repo    = $this->em->getRepository(ComplianceRequirement::class);
        $updated = 0;

        foreach ($levelMap as $requirementId => $level) {
            /** @var ComplianceRequirement|null $req */
            $req = $repo->find($requirementId);
            if ($req === null) {
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
     * Calculate aggregate Reifegrad score for a framework + tenant.
     *
     * Returns an array with:
     *   - average:       float  (arithmetic mean of all assessed rows, 0.0 when none)
     *   - assessed:      int    (rows with maturityCurrent set)
     *   - total:         int    (all tenant-upload rows for this framework)
     *   - byTier:        array  tier → ['average' => float, 'count' => int]
     *   - distribution:  array  level (0-5) => count
     *
     * @return array{average: float, assessed: int, total: int, byTier: array<string, array{average: float, count: int}>, distribution: array<int, int>}
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

            $tier = $req->getCategory() ?? 'information_security';
            $tierData[$tier]['sum']   = ($tierData[$tier]['sum'] ?? 0) + $level;
            $tierData[$tier]['count'] = ($tierData[$tier]['count'] ?? 0) + 1;
        }

        $byTier = [];
        foreach ($tierData as $tier => $data) {
            $byTier[$tier] = [
                'average' => $data['count'] > 0 ? round($data['sum'] / $data['count'], 2) : 0.0,
                'count'   => $data['count'],
            ];
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
