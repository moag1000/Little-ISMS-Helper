<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Incident;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\MfaTokenRepository;
use App\Repository\PatchRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use App\Repository\UserRepository;
use App\Repository\VulnerabilityRepository;

/**
 * NIS2 Compliance Service — computes a metric per Art. 21.2 letter (a..j)
 * plus the Art. 23 reporting timeline and a weighted overall score.
 *
 * Returns pure data (arrays). The controller renders them; downstream
 * widgets can consume individual letters without pulling the full set.
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
class Nis2ComplianceService
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
    ) {
    }

    /**
     * Full dashboard payload — eleven Art. 21.2 letters + Art. 23 timer +
     * weighted overall score.
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
            '21.2.a' => $this->riskManagementPolicies($tenant),
            '21.2.b' => $this->authentication(),
            '21.2.c' => $this->encryption(),
            '21.2.d' => $this->vulnerabilityManagement(),
            '21.2.e' => $this->secureSdlc($tenant),
            '21.2.f' => $this->supplyChainSecurity($tenant),
            '21.2.g' => $this->hrSecurity($tenant),
            '21.2.h' => $this->accessControl(),
            '21.2.i' => $this->assetManagement($tenant),
            '21.2.j' => $this->businessContinuity($tenant),
            '21.2.k' => $this->cryptographicControls($tenant),
        ];

        return [
            'letters' => $letters,
            'article23' => $this->article23Timeline(),
            'overall' => $this->overallScore($letters),
        ];
    }

    /** Art. 21.2.a — documented risk-management policies. */
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

    /** Art. 21.2.b — MFA / cryptographic authentication adoption. */
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
            '21.2.b', 'Authentication / MFA adoption',
            $rate, $rate === null ? '' : '%',
            $this->status($rate, 90.0, 60.0),
            ['users_total' => $total, 'users_with_mfa' => (int) $withMfa]
        );
    }

    /** Art. 21.2.c — encryption in transit / at rest (proxy: control implementation). */
    private function encryption(): array
    {
        $implemented = $this->controlsImplementedInCategory('Technological controls', null);
        $applicable = $this->controlsApplicableInCategory('Technological controls', null);
        $ratio = $applicable > 0 ? round(($implemented / $applicable) * 100, 1) : null;
        return $this->metric(
            '21.2.c', 'Encryption / cryptography',
            $ratio, $ratio === null ? '' : '%',
            $this->status($ratio, 80.0, 50.0),
            ['implemented' => $implemented, 'applicable' => $applicable]
        );
    }

    /** Art. 21.2.d — vulnerability + patch management. */
    private function vulnerabilityManagement(): array
    {
        $totalPatches = $this->patchRepository->count([]);
        $deployed = $this->patchRepository->count(['status' => 'deployed']);
        $failed = $this->patchRepository->count(['status' => 'failed']);
        $patchRate = $totalPatches > 0 ? round(($deployed / $totalPatches) * 100, 1) : null;

        $openVulns = $this->vulnerabilityRepository->count(['status' => 'open']);
        $criticalOpenVulns = $this->vulnerabilityRepository->count(['status' => 'open', 'severity' => 'critical']);

        return $this->metric(
            '21.2.d', 'Vulnerability / patch management',
            $patchRate, $patchRate === null ? '' : '%',
            $criticalOpenVulns > 0 ? 'danger' : $this->status($patchRate, 90.0, 70.0),
            [
                'patches_total' => $totalPatches,
                'patches_deployed' => $deployed,
                'patches_failed' => $failed,
                'open_vulnerabilities' => $openVulns,
                'open_critical_vulnerabilities' => $criticalOpenVulns,
            ]
        );
    }

    /** Art. 21.2.e — security in system acquisition, development and maintenance. */
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

    /** Art. 21.2.f — supply-chain security (supplier assessments). */
    private function supplyChainSecurity(?Tenant $tenant): array
    {
        if ($this->supplierRepository === null) {
            return $this->metricNa('21.2.f', 'Supply chain security');
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
            '21.2.f', 'Supply chain security',
            $rate, $rate === null ? '' : '%',
            $rate === null ? 'na' : $this->status($rate, 90.0, 60.0),
            ['critical_suppliers' => count($critical), 'assessed' => $assessed]
        );
    }

    /** Art. 21.2.g — human-resources security / training completion. */
    private function hrSecurity(?Tenant $tenant): array
    {
        if ($this->trainingRepository === null) {
            return $this->metricNa('21.2.g', 'Human resources security');
        }
        $allTrainings = $this->trainingRepository->findAll();
        $completed = 0;
        $totalAssigned = 0;
        foreach ($allTrainings as $training) {
            if (method_exists($training, 'getAttendees')) {
                $attendees = $training->getAttendees();
                if ($attendees !== null) {
                    $totalAssigned += is_array($attendees) ? count($attendees) : 1;
                }
            }
            if (method_exists($training, 'getStatus') && $training->getStatus() === 'completed') {
                $completed++;
            }
        }
        $rate = count($allTrainings) > 0 ? round(($completed / count($allTrainings)) * 100, 1) : null;
        return $this->metric(
            '21.2.g', 'HR security / training',
            $rate, $rate === null ? '' : '%',
            $rate === null ? 'na' : $this->status($rate, 85.0, 60.0),
            ['trainings_total' => count($allTrainings), 'completed' => $completed]
        );
    }

    /** Art. 21.2.h — access control (active users vs. users with role). */
    private function accessControl(): array
    {
        $totalActive = $this->userRepository->count(['isActive' => true]);
        $withRoles = 0;
        $users = $this->userRepository->findBy(['isActive' => true]);
        foreach ($users as $user) {
            $roles = array_diff($user->getRoles(), ['ROLE_USER']); // drop default
            if ($roles !== []) {
                $withRoles++;
            }
        }
        $rate = $totalActive > 0 ? round(($withRoles / $totalActive) * 100, 1) : null;
        return $this->metric(
            '21.2.h', 'Access control / RBAC',
            $rate, $rate === null ? '' : '%',
            $this->status($rate, 80.0, 50.0),
            ['users_active' => $totalActive, 'users_with_role' => $withRoles]
        );
    }

    /** Art. 21.2.i — asset management (inventory + classification). */
    private function assetManagement(?Tenant $tenant): array
    {
        if ($this->assetRepository === null) {
            return $this->metricNa('21.2.i', 'Asset management');
        }
        $total = $this->assetRepository->count([]);
        $classified = 0;
        foreach ($this->assetRepository->findAll() as $asset) {
            if (method_exists($asset, 'getConfidentialityValue')
                && $asset->getConfidentialityValue() !== null
                && $asset->getConfidentialityValue() > 0) {
                $classified++;
            }
        }
        $rate = $total > 0 ? round(($classified / $total) * 100, 1) : null;
        return $this->metric(
            '21.2.i', 'Asset management',
            $rate, $rate === null ? '' : '%',
            $this->status($rate, 90.0, 70.0),
            ['assets_total' => $total, 'assets_classified' => $classified]
        );
    }

    /** Art. 21.2.j — business continuity / crisis management. */
    private function businessContinuity(?Tenant $tenant): array
    {
        if ($this->bcPlanRepository === null) {
            return $this->metricNa('21.2.j', 'Business continuity');
        }
        $activePlans = $this->bcPlanRepository->count(['status' => 'active']);
        $allPlans = $this->bcPlanRepository->count([]);
        $rate = $allPlans > 0 ? round(($activePlans / $allPlans) * 100, 1) : null;
        return $this->metric(
            '21.2.j', 'Business continuity / crisis management',
            $rate, $rate === null ? '' : '%',
            $rate === null ? 'na' : $this->status($rate, 80.0, 50.0),
            ['plans_total' => $allPlans, 'plans_active' => $activePlans]
        );
    }

    /** Art. 21.2.k — cryptographic controls policy (post-quantum readiness). */
    private function cryptographicControls(?Tenant $tenant): array
    {
        $implemented = $this->controlsImplementedMatching('8.24', $tenant);
        $applicable = $this->controlsApplicableMatching('8.24', $tenant);
        $ratio = $applicable > 0 ? round(($implemented / $applicable) * 100, 1) : null;
        return $this->metric(
            '21.2.k', 'Cryptographic controls policy',
            $ratio, $ratio === null ? '' : '%',
            $this->status($ratio, 80.0, 50.0),
            ['implemented' => $implemented, 'applicable' => $applicable]
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
     * Weighted overall NIS2 score across the eleven Art. 21.2 letters.
     * Letters without applicable data are excluded from the average.
     *
     * @param array<string, array> $letters
     * @return array{score: float, weighted: array<string,float>, applicable_count: int}
     */
    private function overallScore(array $letters): array
    {
        $weights = [
            '21.2.a' => 1.0, '21.2.b' => 1.0, '21.2.c' => 1.0, '21.2.d' => 1.2,
            '21.2.e' => 0.9, '21.2.f' => 1.0, '21.2.g' => 0.8, '21.2.h' => 1.0,
            '21.2.i' => 0.9, '21.2.j' => 1.1, '21.2.k' => 0.8,
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
