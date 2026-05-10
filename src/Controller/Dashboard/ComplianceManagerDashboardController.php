<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Repository\AuditFindingRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\DocumentRepository;
use App\Repository\InternalAuditRepository;
use App\Service\ComplianceAnalyticsService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Audit V3 C2 — Compliance-Manager (Head-of-GRC) Dashboard.
 *
 * Pendant to existing CISO/Risk-Manager/Auditor/Board dashboards.
 * Surfaces:
 *   - Coverage heatmap per framework
 *   - Cross-framework mapping coverage
 *   - Open findings by severity
 *   - Audit schedule (next 90 days)
 *   - Document approval queue
 *   - Top-3 frameworks with lowest coverage
 *   - Quick-actions (start wizard, create mapping, generate audit-bundle)
 */
#[Route('/dashboards', name: 'app_dashboard_')]
class ComplianceManagerDashboardController extends AbstractController
{
    public function __construct(
        private readonly ComplianceAnalyticsService $analytics,
        private readonly ComplianceFrameworkRepository $frameworkRepo,
        private readonly AuditFindingRepository $findingRepo,
        private readonly InternalAuditRepository $auditRepo,
        private readonly DocumentRepository $documentRepo,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/compliance-manager', name: 'compliance_manager')]
    #[IsGranted('ROLE_MANAGER')]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        // Framework coverage
        $frameworkComparison = $this->analytics->getFrameworkComparison();
        $frameworks = $frameworkComparison['frameworks'] ?? [];

        // Sort & take Top-3 lowest coverage
        $sorted = $frameworks;
        usort($sorted, static function (array $a, array $b): int {
            return ($a['compliance'] ?? 0) <=> ($b['compliance'] ?? 0);
        });
        $lowestCoverage = array_slice($sorted, 0, 3);

        // Findings by severity
        $findingsBySeverity = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        if ($tenant) {
            foreach ($this->findingRepo->findOpenByTenant($tenant) as $finding) {
                $sev = method_exists($finding, 'getSeverity') ? $finding->getSeverity() : 'medium';
                if (isset($findingsBySeverity[$sev])) {
                    $findingsBySeverity[$sev]++;
                }
            }
        }

        // Audits next 90 days
        $upcomingAudits = [];
        if ($tenant) {
            $cutoff = new DateTimeImmutable('+90 days');
            $audits = method_exists($this->auditRepo, 'findUpcoming')
                ? $this->auditRepo->findUpcoming($tenant, $cutoff)
                : $this->auditRepo->findBy(['tenant' => $tenant], ['plannedDate' => 'ASC'], 10);
            foreach ($audits as $audit) {
                if (method_exists($audit, 'getPlannedDate') && $audit->getPlannedDate() instanceof \DateTimeInterface
                    && $audit->getPlannedDate() > new DateTimeImmutable()
                    && $audit->getPlannedDate() <= $cutoff) {
                    $upcomingAudits[] = $audit;
                }
            }
        }

        // Documents pending approval
        $pendingDocs = [];
        if ($tenant) {
            $pendingDocs = $this->documentRepo->createQueryBuilder('d')
                ->andWhere('d.tenant = :tenant')
                ->andWhere('d.status IN (:statuses)')
                ->setParameter('tenant', $tenant)
                ->setParameter('statuses', ['pending_approval', 'in_review', 'review'])
                ->setMaxResults(20)
                ->getQuery()
                ->getResult();
        }

        // Cross-framework mapping coverage (best-effort summary)
        $mappingCoverage = $frameworkComparison['summary']['cross_mapping_coverage'] ?? null;

        return $this->render('dashboards/compliance_manager.html.twig', [
            'dashboard' => [
                'frameworks'         => $frameworks,
                'lowest_coverage'    => $lowestCoverage,
                'findings_severity'  => $findingsBySeverity,
                'findings_total'     => array_sum($findingsBySeverity),
                'upcoming_audits'    => $upcomingAudits,
                'pending_docs'       => $pendingDocs,
                'mapping_coverage'   => $mappingCoverage,
                'frameworks_active'  => count($frameworks),
                'avg_compliance'     => $frameworkComparison['summary']['average_compliance'] ?? 0,
                'at_risk_count'      => $frameworkComparison['summary']['at_risk'] ?? 0,
            ],
        ]);
    }
}
