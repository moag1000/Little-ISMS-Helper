<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Incident;
use App\Entity\Tenant;
use App\Enum\ManagementReviewStatus;
use App\Enum\TrainingStatus;
use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\MfaTokenRepository;
use App\Repository\PatchRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use App\Repository\UserRepository;
use App\Repository\VulnerabilityRepository;

/**
 * NIS2 Compliance Service — computes a metric per Art. 21(2) letter (a..j)
 * plus the Art. 23 reporting timeline and a weighted overall score.
 *
 * Returns pure data (arrays). The controller renders them; downstream
 * widgets can consume individual letters without pulling the full set.
 *
 * Key→method mapping follows the official NIS2 Directive (EU 2022/2555)
 * Art. 21(2)(a)-(j) ordering exactly — 10 measures, no letter k:
 *   (a) risk analysis & security of information systems → riskManagementPolicies()
 *   (b) incident handling                              → incidentHandling()
 *   (c) business continuity / BCM / backup            → businessContinuity()
 *   (d) supply chain security                         → supplyChainSecurity()
 *   (e) security in acquisition, development, maint.  → secureSdlc()
 *   (f) effectiveness assessment of cyber measures    → effectivenessAssessment()
 *   (g) cyber hygiene + cybersecurity training        → cyberHygieneAndTraining()
 *   (h) cryptography and encryption                   → cryptographicControls()
 *   (i) HR security + access control + asset mgmt     → accessControlAndAssetMgmt()
 *   (j) MFA + secured communications                  → authentication()
 *
 * All metrics follow the same shape:
 *   [
 *     'letter'      => '21.2.a',
 *     'title'       => string,
 *     'value'       => int|float|null,
 *     'unit'        => string,
 *     'status'      => 'good'|'warning'|'danger'|'info'|'na',
 *     'details'     => array<string,mixed>,
 *   ]
 *
 * Status thresholds are intentionally conservative — the goal is to flag
 * measurable shortcomings without painting every tenant red out of the box.
 */
final class Nis2ComplianceService
{
    public function __construct(
        private readonly IncidentRepository $incidentRepository,
        private readonly MfaTokenRepository $mfaTokenRepository,
        private readonly UserRepository $userRepository,
        private readonly VulnerabilityRepository $vulnerabilityRepository,
        private readonly PatchRepository $patchRepository,
        private readonly ?ControlRepository $controlRepository = null,
        private readonly ?ComplianceFrameworkRepository $frameworkRepository = null,
        private readonly ?AssetRepository $assetRepository = null,
        private readonly ?SupplierRepository $supplierRepository = null,
        private readonly ?TrainingRepository $trainingRepository = null,
        private readonly ?BusinessContinuityPlanRepository $bcPlanRepository = null,
        private readonly ?ManagementReviewRepository $managementReviewRepository = null,
    ) {
    }

    /**
     * Full dashboard payload — ten Art. 21(2) letters (a..j, directive-correct)
     * + Art. 23 timer + weighted overall score.
     *
     * Letter ordering follows EU 2022/2555 Art. 21(2)(a)-(j) exactly.
     * The former 11-letter legacy grid (including the non-existent 21.2.k) has
     * been removed; each letter now maps to its directive-correct measure.
     *
     * @return array{
     *     letters: array<string, array>,
     *     article23: array,
     *     overall: array{score: float, weighted: array<string,float>, applicable_count: int}
     * }
     */
    public function getDashboardPayload(?Tenant $tenant = null): array
    {
        $letters = [
            '21.2.a' => $this->riskManagementPolicies($tenant),          // (a) risk analysis & InfoSys security
            '21.2.b' => $this->incidentHandling(),                        // (b) incident handling
            '21.2.c' => $this->businessContinuity($tenant),               // (c) BCM / backup / crisis mgmt
            '21.2.d' => $this->supplyChainSecurity($tenant),              // (d) supply chain security
            '21.2.e' => $this->secureSdlc($tenant),                       // (e) secure development & acquisition
            '21.2.f' => $this->effectivenessAssessment($tenant),          // (f) effectiveness assessment
            '21.2.g' => $this->cyberHygieneAndTraining($tenant),          // (g) cyber hygiene + training
            '21.2.h' => $this->cryptographicControls($tenant),            // (h) cryptography & encryption
            '21.2.i' => $this->accessControlAndAssetMgmt($tenant),        // (i) HR security + access ctrl + asset mgmt
            '21.2.j' => $this->authentication(),                          // (j) MFA + secured communications
        ];

        return [
            'letters' => $letters,
            'article23' => $this->article23Timeline(),
            'overall' => $this->overallScore($letters),
        ];
    }

    /** Art. 21(2)(a) — documented risk-management policies (risk analysis + InfoSys security). */
    private function riskManagementPolicies(?Tenant $tenant): array
    {
        $controls = $this->controlsImplementedInCategory('Organizational controls', $tenant);
        $applicable = $this->controlsApplicableInCategory('Organizational controls', $tenant);
        $ratio = $applicable > 0 ? round(($controls / $applicable) * 100, 1) : null;
        return $this->metric(
            '21.2.a', 'Risk management policies',
            $ratio, $ratio === null ? '' : '%',
            $this->status($ratio, 80.0, 50.0),
            ['implemented' => $controls, 'applicable' => $applicable]
        );
    }

    /**
     * Art. 21(2)(b) — incident handling (detection, classification, containment,
     * eradication, recovery). Proxy: ratio of incidents that have reached the
     * "resolved" / "closed" lifecycle state, indicating the handling process was
     * followed to completion.
     */
    private function incidentHandling(): array
    {
        $total = $this->incidentRepository->count([]);
        if ($total === 0) {
            return $this->metric(
                '21.2.b', 'Incident handling',
                null, '',
                'info',
                ['total_incidents' => 0, 'resolved' => 0]
            );
        }
        $resolved = $this->incidentRepository->count(['status' => 'resolved']);
        $closed = $this->incidentRepository->count(['status' => 'closed']);
        $handledCount = $resolved + $closed;
        $rate = round(($handledCount / $total) * 100, 1);
        return $this->metric(
            '21.2.b', 'Incident handling',
            $rate, '%',
            $this->status($rate, 80.0, 50.0),
            ['total_incidents' => $total, 'resolved' => $resolved, 'closed' => $closed]
        );
    }

    /** Art. 21(2)(c) — business continuity / BCM / backup / crisis management. */
    private function businessContinuity(?Tenant $tenant): array
    {
        if ($this->bcPlanRepository === null) {
            return $this->metricNa('21.2.c', 'Business continuity');
        }
        $activePlans = $this->bcPlanRepository->count(['status' => 'active']);
        $allPlans = $this->bcPlanRepository->count([]);
        $rate = $allPlans > 0 ? round(($activePlans / $allPlans) * 100, 1) : null;
        return $this->metric(
            '21.2.c', 'Business continuity / crisis management',
            $rate, $rate === null ? '' : '%',
            $rate === null ? 'na' : $this->status($rate, 80.0, 50.0),
            ['plans_total' => $allPlans, 'plans_active' => $activePlans]
        );
    }

    /** Art. 21(2)(d) — supply-chain security (supplier assessments). */
    private function supplyChainSecurity(?Tenant $tenant): array
    {
        if ($this->supplierRepository === null) {
            return $this->metricNa('21.2.d', 'Supply chain security');
        }
        $critical = $this->supplierRepository->findBy(['criticality' => ['critical', 'high']]);
        $assessed = 0;
        foreach ($critical as $supplier) {
            if (method_exists($supplier, 'getLastSecurityAssessment') && $supplier->getLastSecurityAssessment() !== null) {
                $assessed++;
            }
        }
        $rate = count($critical) > 0 ? round(($assessed / count($critical)) * 100, 1) : null;
        return $this->metric(
            '21.2.d', 'Supply chain security',
            $rate, $rate === null ? '' : '%',
            $rate === null ? 'na' : $this->status($rate, 90.0, 60.0),
            ['critical_suppliers' => count($critical), 'assessed' => $assessed]
        );
    }

    /** Art. 21(2)(e) — security in system acquisition, development and maintenance. */
    private function secureSdlc(?Tenant $tenant): array
    {
        $implemented = $this->controlsImplementedMatching('8.2', $tenant);
        $applicable = $this->controlsApplicableMatching('8.2', $tenant);
        $ratio = $applicable > 0 ? round(($implemented / $applicable) * 100, 1) : null;
        return $this->metric(
            '21.2.e', 'Security in development & acquisition',
            $ratio, $ratio === null ? '' : '%',
            $this->status($ratio, 80.0, 50.0),
            ['implemented' => $implemented, 'applicable' => $applicable]
        );
    }

    /**
     * Art. 21(2)(f) — effectiveness assessment of cybersecurity measures
     * (ISO 27001 Clause 9.1 / 9.3 proxy: management-review completion rate).
     * A completed management review demonstrates the organisation evaluates
     * the effectiveness of its security controls periodically.
     */
    private function effectivenessAssessment(?Tenant $tenant): array
    {
        if ($this->managementReviewRepository === null) {
            return $this->metricNa('21.2.f', 'Effectiveness assessment');
        }
        $total = $this->managementReviewRepository->count([]);
        if ($total === 0) {
            return $this->metric(
                '21.2.f', 'Effectiveness assessment',
                null, '',
                'info',
                ['total_reviews' => 0, 'completed' => 0]
            );
        }
        $completed = $this->managementReviewRepository->count(['status' => ManagementReviewStatus::Completed->value]);
        $rate = round(($completed / $total) * 100, 1);
        return $this->metric(
            '21.2.f', 'Effectiveness assessment',
            $rate, '%',
            $this->status($rate, 80.0, 50.0),
            ['total_reviews' => $total, 'completed' => $completed]
        );
    }

    /**
     * Art. 21(2)(g) — cyber hygiene practices + cybersecurity training for staff.
     * Proxy: training completion rate (same semantic as prior hrSecurity() method).
     */
    private function cyberHygieneAndTraining(?Tenant $tenant): array
    {
        if ($this->trainingRepository === null) {
            return $this->metricNa('21.2.g', 'Cyber hygiene & training');
        }
        $allTrainings = $this->trainingRepository->findAll();
        $completed = 0;
        foreach ($allTrainings as $training) {
            if (method_exists($training, 'getStatus') && $training->getStatus() === TrainingStatus::Completed->value) {
                $completed++;
            }
        }
        $rate = count($allTrainings) > 0 ? round(($completed / count($allTrainings)) * 100, 1) : null;
        return $this->metric(
            '21.2.g', 'Cyber hygiene & training',
            $rate, $rate === null ? '' : '%',
            $rate === null ? 'na' : $this->status($rate, 85.0, 60.0),
            ['trainings_total' => count($allTrainings), 'completed' => $completed]
        );
    }

    /**
     * Art. 21(2)(h) — cryptography and encryption (use of cryptography policy,
     * post-quantum readiness). Proxy: A.8.24 control implementation rate.
     */
    private function cryptographicControls(?Tenant $tenant): array
    {
        $implemented = $this->controlsImplementedMatching('8.24', $tenant);
        $applicable = $this->controlsApplicableMatching('8.24', $tenant);
        $ratio = $applicable > 0 ? round(($implemented / $applicable) * 100, 1) : null;
        return $this->metric(
            '21.2.h', 'Cryptographic controls policy',
            $ratio, $ratio === null ? '' : '%',
            $this->status($ratio, 80.0, 50.0),
            ['implemented' => $implemented, 'applicable' => $applicable]
        );
    }

    /**
     * Art. 21(2)(i) — HR security + access control + asset management.
     * Combined metric: average of (a) RBAC coverage (users with non-default role)
     * and (b) asset classification rate. When the asset module is inactive,
     * falls back to the RBAC rate alone.
     */
    private function accessControlAndAssetMgmt(?Tenant $tenant): array
    {
        // Access control sub-metric
        $totalActive = $this->userRepository->count(['isActive' => true]);
        $withRoles = 0;
        $users = $this->userRepository->findBy(['isActive' => true]);
        foreach ($users as $user) {
            $roles = array_diff($user->getRoles(), ['ROLE_USER']); // drop default ROLE_USER
            if ($roles !== []) {
                $withRoles++;
            }
        }
        $rbacRate = $totalActive > 0 ? round(($withRoles / $totalActive) * 100, 1) : null;

        // Asset management sub-metric
        if ($this->assetRepository !== null) {
            $totalAssets = $this->assetRepository->count([]);
            $classified = 0;
            foreach ($this->assetRepository->findAll() as $asset) {
                if (method_exists($asset, 'getConfidentialityValue')
                    && $asset->getConfidentialityValue() !== null
                    && $asset->getConfidentialityValue() > 0) {
                    $classified++;
                }
            }
            $assetRate = $totalAssets > 0 ? round(($classified / $totalAssets) * 100, 1) : null;
        } else {
            $assetRate = null;
        }

        // Combined: average if both available, fallback to whichever is non-null
        if ($rbacRate !== null && $assetRate !== null) {
            $combined = round(($rbacRate + $assetRate) / 2.0, 1);
        } elseif ($rbacRate !== null) {
            $combined = $rbacRate;
        } elseif ($assetRate !== null) {
            $combined = $assetRate;
        } else {
            $combined = null;
        }

        return $this->metric(
            '21.2.i', 'HR security / access control / asset management',
            $combined, $combined === null ? '' : '%',
            $combined === null ? 'na' : $this->status($combined, 80.0, 50.0),
            [
                'users_active' => $totalActive,
                'users_with_role' => $withRoles,
                'rbac_rate' => $rbacRate,
                'asset_classification_rate' => $assetRate,
            ]
        );
    }

    /**
     * Art. 21(2)(j) — multi-factor authentication (MFA) + secured communications.
     * Proxy: MFA adoption rate across active users.
     */
    private function authentication(): array
    {
        $total = $this->userRepository->count(['isActive' => true]);
        $withMfa = $this->mfaTokenRepository->createQueryBuilder('m')
            ->select('COUNT(DISTINCT m.user)')
            ->where('m.isActive = true')
            ->getQuery()
            ->getSingleScalarResult();
        $rate = $total > 0 ? round(((int) $withMfa / $total) * 100, 1) : null;
        return $this->metric(
            '21.2.j', 'MFA / secured communications',
            $rate, $rate === null ? '' : '%',
            $this->status($rate, 90.0, 60.0),
            ['users_total' => $total, 'users_with_mfa' => (int) $withMfa]
        );
    }

    /**
     * Art. 23 — reporting timeline: how many NIS2-relevant incidents met
     * each deadline (early warning 24h, notification 72h, final report 1 month).
     */
    public function article23Timeline(): array
    {
        $nis2Incidents = $this->incidentRepository->findBy(['nis2Category' => ['operational', 'security', 'privacy', 'availability']]);
        $totalNis2 = count($nis2Incidents);
        $earlyOk = $detailedOk = $finalOk = 0;
        $overdue = 0;
        foreach ($nis2Incidents as $incident) {
            if ($this->incidentMetEarlyWarning($incident)) {
                $earlyOk++;
            }
            if ($this->incidentMetDetailedNotification($incident)) {
                $detailedOk++;
            }
            if ($this->incidentMetFinalReport($incident)) {
                $finalOk++;
            }
            if ($this->incidentIsReportingOverdue($incident)) {
                $overdue++;
            }
        }
        return [
            'letter' => '23',
            'title' => 'Incident reporting (Art. 23)',
            'total_nis2_incidents' => $totalNis2,
            'early_warning_ok' => $earlyOk,
            'detailed_notification_ok' => $detailedOk,
            'final_report_ok' => $finalOk,
            'overdue' => $overdue,
            'compliance_rate' => $totalNis2 > 0
                ? round((($earlyOk + $detailedOk + $finalOk) / ($totalNis2 * 3)) * 100, 1)
                : null,
            'status' => $overdue > 0 ? 'danger' : ($totalNis2 === 0 ? 'info' : 'good'),
        ];
    }

    /**
     * Weighted overall NIS2 score across the ten Art. 21(2) letters (a..j).
     * Letters without applicable data are excluded from the average.
     * Weights reflect regulatory criticality per directive text and ENISA guidance.
     *
     * @param array<string, array> $letters
     * @return array{score: float, weighted: array<string,float>, applicable_count: int}
     */
    private function overallScore(array $letters): array
    {
        $weights = [
            '21.2.a' => 1.0, // risk management policies
            '21.2.b' => 1.2, // incident handling (critical — mandatory NIS2 Art. 23 link)
            '21.2.c' => 1.1, // BCM / business continuity
            '21.2.d' => 1.0, // supply chain security
            '21.2.e' => 0.9, // secure development
            '21.2.f' => 0.8, // effectiveness assessment
            '21.2.g' => 0.8, // cyber hygiene + training
            '21.2.h' => 1.0, // cryptography
            '21.2.i' => 1.0, // HR security / access control / asset mgmt
            '21.2.j' => 1.1, // MFA + secured communications (critical)
        ];
        $sum = 0.0;
        $weightSum = 0.0;
        $applicable = 0;
        $perLetter = [];
        foreach ($letters as $code => $data) {
            $value = $data['value'] ?? null;
            if ($value === null) {
                $perLetter[$code] = 0.0;
                continue;
            }
            $weight = $weights[$code] ?? 1.0;
            $sum += ((float) $value) * $weight;
            $weightSum += $weight;
            $perLetter[$code] = round((float) $value, 1);
            $applicable++;
        }
        $score = $weightSum > 0.0 ? round($sum / $weightSum, 1) : 0.0;
        return [
            'score' => $score,
            'weighted' => $perLetter,
            'applicable_count' => $applicable,
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function controlsImplementedInCategory(string $category, ?Tenant $tenant): int
    {
        if ($this->controlRepository === null) {
            return 0;
        }
        $qb = $this->controlRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.category = :cat')
            ->andWhere('c.applicable = true')
            ->andWhere('c.implementationStatus = :status')
            ->setParameter('cat', $category)
            ->setParameter('status', 'implemented');
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function controlsApplicableInCategory(string $category, ?Tenant $tenant): int
    {
        if ($this->controlRepository === null) {
            return 0;
        }
        $qb = $this->controlRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.category = :cat')
            ->andWhere('c.applicable = true')
            ->setParameter('cat', $category);
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function controlsImplementedMatching(string $controlIdPrefix, ?Tenant $tenant): int
    {
        if ($this->controlRepository === null) {
            return 0;
        }
        $qb = $this->controlRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.controlId LIKE :prefix')
            ->andWhere('c.applicable = true')
            ->andWhere('c.implementationStatus = :status')
            ->setParameter('prefix', $controlIdPrefix . '%')
            ->setParameter('status', 'implemented');
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function controlsApplicableMatching(string $controlIdPrefix, ?Tenant $tenant): int
    {
        if ($this->controlRepository === null) {
            return 0;
        }
        $qb = $this->controlRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.controlId LIKE :prefix')
            ->andWhere('c.applicable = true')
            ->setParameter('prefix', $controlIdPrefix . '%');
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function incidentMetEarlyWarning(Incident $incident): bool
    {
        if (!method_exists($incident, 'getEarlyWarningReportedAt')) {
            return false;
        }
        $detected = $incident->getDetectedAt();
        $reported = $incident->getEarlyWarningReportedAt();
        if ($detected === null || $reported === null) {
            return false;
        }
        return $reported->getTimestamp() - $detected->getTimestamp() <= 24 * 3600;
    }

    private function incidentMetDetailedNotification(Incident $incident): bool
    {
        if (!method_exists($incident, 'getDetailedNotificationReportedAt')) {
            return false;
        }
        $detected = $incident->getDetectedAt();
        $reported = $incident->getDetailedNotificationReportedAt();
        if ($detected === null || $reported === null) {
            return false;
        }
        return $reported->getTimestamp() - $detected->getTimestamp() <= 72 * 3600;
    }

    private function incidentMetFinalReport(Incident $incident): bool
    {
        if (!method_exists($incident, 'getFinalReportSubmittedAt')) {
            return false;
        }
        $detected = $incident->getDetectedAt();
        $reported = $incident->getFinalReportSubmittedAt();
        if ($detected === null || $reported === null) {
            return false;
        }
        return $reported->getTimestamp() - $detected->getTimestamp() <= 30 * 86400;
    }

    private function incidentIsReportingOverdue(Incident $incident): bool
    {
        $detected = $incident->getDetectedAt();
        if ($detected === null) {
            return false;
        }
        $now = new \DateTimeImmutable();
        $ageSeconds = $now->getTimestamp() - $detected->getTimestamp();

        if ($ageSeconds > 24 * 3600
            && method_exists($incident, 'getEarlyWarningReportedAt')
            && $incident->getEarlyWarningReportedAt() === null) {
            return true;
        }
        if ($ageSeconds > 72 * 3600
            && method_exists($incident, 'getDetailedNotificationReportedAt')
            && $incident->getDetailedNotificationReportedAt() === null) {
            return true;
        }
        if ($ageSeconds > 30 * 86400
            && method_exists($incident, 'getFinalReportSubmittedAt')
            && $incident->getFinalReportSubmittedAt() === null) {
            return true;
        }
        return false;
    }

    private function metric(string $letter, string $title, mixed $value, string $unit, string $status, array $details = []): array
    {
        return [
            'letter' => $letter,
            'title' => $title,
            'value' => $value,
            'unit' => $unit,
            'status' => $status,
            'details' => $details,
        ];
    }

    private function metricNa(string $letter, string $title): array
    {
        return [
            'letter' => $letter,
            'title' => $title,
            'value' => null,
            'unit' => '',
            'status' => 'na',
            'details' => ['reason' => 'module_inactive'],
        ];
    }

    private function status(?float $value, float $good, float $warning): string
    {
        if ($value === null) {
            return 'info';
        }
        if ($value >= $good) {
            return 'good';
        }
        if ($value >= $warning) {
            return 'warning';
        }
        return 'danger';
    }
}
