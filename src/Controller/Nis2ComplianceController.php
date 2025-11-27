<?php

namespace App\Controller;

use DateTime;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\IncidentRepository;
use App\Repository\MfaTokenRepository;
use App\Repository\UserRepository;
use App\Repository\VulnerabilityRepository;
use App\Repository\PatchRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * NIS2 Compliance Dashboard Controller
 *
 * Provides real-time compliance monitoring for NIS2 Directive (EU 2022/2555)
 * Article 21: Cybersecurity Risk Management Measures
 * Article 23: Incident Reporting
 *
 * Note: This dashboard is only available when the NIS2 framework is installed and active.
 * NIS2 is only mandatory for essential/important entities as defined in the directive.
 */
#[IsGranted('ROLE_MANAGER')]
class Nis2ComplianceController extends AbstractController
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly MfaTokenRepository $mfaTokenRepository,
        private readonly UserRepository $userRepository,
        private readonly VulnerabilityRepository $vulnerabilityRepository,
        private readonly PatchRepository $patchRepository,
        private readonly TranslatorInterface $translator
    ) {
    }

    #[Route('/nis2-compliance', name: 'app_nis2_compliance_dashboard')]
    public function dashboard(): Response
    {
        // Check if NIS2 framework exists and is active
        $nis2Framework = $this->complianceFrameworkRepository->findOneBy(['code' => 'NIS2']);

        if (!$nis2Framework) {
            // NIS2 framework not installed - redirect to compliance overview
            $this->addFlash('info', $this->translator->trans(
                'nis2.not_installed',
                [],
                'messages'
            ) ?: 'NIS2 framework is not installed. If your organization is subject to NIS2, please load the framework from the compliance frameworks page.');

            return $this->redirectToRoute('app_compliance_index');
        }

        if (!$nis2Framework->isActive()) {
            // NIS2 framework exists but is deactivated - assume not relevant for this organization
            $this->addFlash('warning', $this->translator->trans(
                'nis2.not_active',
                [],
                'messages'
            ) ?: 'NIS2 framework is deactivated. This indicates that NIS2 is not relevant for your organization. Activate it in the compliance frameworks if needed.');

            return $this->redirectToRoute('app_compliance_index');
        }
        // 1. MFA Adoption Rate (Art. 21.2.b)
        $totalUsers = $this->userRepository->count([]);
        $usersWithMfa = $this->mfaTokenRepository->createQueryBuilder('m')
            ->select('COUNT(DISTINCT m.user)')
            ->where('m.isActive = true')
            ->getQuery()
            ->getSingleScalarResult();
        $mfaAdoptionRate = $totalUsers > 0 ? round(($usersWithMfa / $totalUsers) * 100, 1) : 0;

        // 2. Incident Reporting Compliance (Art. 23)
        $nis2Incidents = $this->incidentRepository->createQueryBuilder('i')
            ->where('i.crossBorderImpact = true OR i.nis2Category IS NOT NULL')
            ->getQuery()
            ->getResult();

        $totalNis2Incidents = count($nis2Incidents);
        $earlyWarningCompliant = 0;
        $detailedNotificationCompliant = 0;
        $finalReportCompliant = 0;
        $overdueIncidents = [];

        foreach ($nis2Incidents as $nis2Incident) {
            if ($nis2Incident->getEarlyWarningReportedAt() !== null && !$nis2Incident->isEarlyWarningOverdue()) {
                $earlyWarningCompliant++;
            }
            if ($nis2Incident->getDetailedNotificationReportedAt() !== null && !$nis2Incident->isDetailedNotificationOverdue()) {
                $detailedNotificationCompliant++;
            }
            if ($nis2Incident->getFinalReportSubmittedAt() !== null && !$nis2Incident->isFinalReportOverdue()) {
                $finalReportCompliant++;
            }

            // Track overdue incidents
            if ($nis2Incident->isEarlyWarningOverdue() || $nis2Incident->isDetailedNotificationOverdue() || $nis2Incident->isFinalReportOverdue()) {
                $overdueIncidents[] = $nis2Incident;
            }
        }

        $reportingComplianceRate = $totalNis2Incidents > 0
            ? round((($earlyWarningCompliant + $detailedNotificationCompliant + $finalReportCompliant) / ($totalNis2Incidents * 3)) * 100, 1)
            : 100;

        // 3. Vulnerability Management (Art. 21.2.d)
        $totalVulnerabilities = $this->vulnerabilityRepository->count([]);
        $criticalVulnerabilities = $this->vulnerabilityRepository->count(['severity' => 'critical']);
        $highVulnerabilities = $this->vulnerabilityRepository->count(['severity' => 'high']);
        $overdueVulnerabilities = $this->vulnerabilityRepository->createQueryBuilder('v')
            ->where('v.remediationDeadline < :now')
            ->andWhere('v.status NOT IN (:closedStatuses)')
            ->setParameter('now', new DateTime())
            ->setParameter('closedStatuses', ['closed', 'false_positive'])
            ->getQuery()
            ->getResult();

        // 4. Patch Management (Art. 21.2.d)
        $totalPatches = $this->patchRepository->count([]);
        $deployedPatches = $this->patchRepository->count(['status' => 'deployed']);
        $failedPatches = $this->patchRepository->count(['status' => 'failed']);
        $patchDeploymentRate = $totalPatches > 0 ? round(($deployedPatches / $totalPatches) * 100, 1) : 0;

        // 5. Overall NIS2 Compliance Score
        $complianceScore = round(($mfaAdoptionRate + $reportingComplianceRate + $patchDeploymentRate) / 3, 1);

        return $this->render('nis2_compliance/dashboard.html.twig', [
            // MFA Metrics
            'total_users' => $totalUsers,
            'users_with_mfa' => $usersWithMfa,
            'mfa_adoption_rate' => $mfaAdoptionRate,

            // Incident Reporting Metrics
            'total_nis2_incidents' => $totalNis2Incidents,
            'early_warning_compliant' => $earlyWarningCompliant,
            'detailed_notification_compliant' => $detailedNotificationCompliant,
            'final_report_compliant' => $finalReportCompliant,
            'reporting_compliance_rate' => $reportingComplianceRate,
            'overdue_incidents' => $overdueIncidents,

            // Vulnerability Metrics
            'total_vulnerabilities' => $totalVulnerabilities,
            'critical_vulnerabilities' => $criticalVulnerabilities,
            'high_vulnerabilities' => $highVulnerabilities,
            'overdue_vulnerabilities' => $overdueVulnerabilities,

            // Patch Metrics
            'total_patches' => $totalPatches,
            'deployed_patches' => $deployedPatches,
            'failed_patches' => $failedPatches,
            'patch_deployment_rate' => $patchDeploymentRate,

            // Overall Compliance
            'overall_compliance_score' => $complianceScore,
        ]);
    }
}
