<?php

declare(strict_types=1);

namespace App\Controller\Component;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AuditFindingRepository;
use App\Service\PolicyWizard\PolicyAcknowledgementService;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Policy-Wizard W7-E — CISO board-reporting widget.
 *
 * Embed-fragment that renders a small grid of KPI tiles for the
 * dashboard. Restricted to ROLE_GROUP_CISO scope (which inherits
 * ROLE_AUDITOR) so non-CISO users see no widget at all — the controller
 * returns an empty 204 response and the dashboard layout collapses.
 *
 * KPIs (per CISO "What's missing — Board-Reporting" feedback,
 * `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`):
 *   - Total policies generated (counted via DocumentRepository, scoped to
 *     documents that carry a generatedFromTemplate provenance link).
 *   - Pending approvals (Documents with status='pending_approval' or
 *     section-level status='dpo_sign_off').
 *   - Open audit findings (cross-ref to the W7-D
 *     {@see App\Service\PolicyWizard\Step\TargetedFindingReferenceStep}
 *     OpenFinding rule).
 *   - Policy acknowledgement coverage % (PolicyAcknowledgementService
 *     averages across all published wizard documents in scope).
 *   - DPO veto count this quarter (DocumentSection with
 *     status='rejected' AND approvalRole='dpo' AND
 *     rejectedAt within current quarter).
 */
final class CisoBoardReportingWidgetController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PolicyAcknowledgementService $acknowledgementService,
        private readonly AuditFindingRepository $auditFindingRepository,
    ) {
    }

    #[IsGranted('ROLE_GROUP_CISO')]
    public function widget(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $tenant = $user->getTenant();
        if (!$tenant instanceof Tenant) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $kpis = $this->collectKpis($tenant);

        return $this->render('_components/_ciso_board_reporting_widget.html.twig', [
            'kpis' => $kpis,
        ]);
    }

    /**
     * Aggregate the five KPIs. Public so the test-suite can call it
     * directly without going through HTTP / IsGranted.
     *
     * @return array{
     *     total_policies: int,
     *     pending_approvals: int,
     *     open_findings: int,
     *     ack_coverage_percent: float,
     *     dpo_veto_count: int,
     * }
     */
    public function collectKpis(Tenant $tenant): array
    {
        return [
            'total_policies' => $this->countTotalPolicies($tenant),
            'pending_approvals' => $this->countPendingApprovals($tenant),
            'open_findings' => $this->countOpenFindings($tenant),
            'ack_coverage_percent' => $this->averageAckCoverage($tenant),
            'dpo_veto_count' => $this->countDpoVetoesThisQuarter($tenant),
        ];
    }

    private function countTotalPolicies(Tenant $tenant): int
    {
        $result = $this->entityManager->getRepository(Document::class)
            ->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.tenant = :tenant')
            ->andWhere('d.generatedFromTemplate IS NOT NULL')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    private function countPendingApprovals(Tenant $tenant): int
    {
        $documentLevel = $this->entityManager->getRepository(Document::class)
            ->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status = :status')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'pending_approval')
            ->getQuery()
            ->getSingleScalarResult();

        $sectionLevel = $this->entityManager->getRepository(DocumentSection::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.tenant = :tenant')
            ->andWhere('s.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', DocumentSection::STATUS_DPO_SIGN_OFF)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $documentLevel + (int) $sectionLevel;
    }

    private function countOpenFindings(Tenant $tenant): int
    {
        return count($this->auditFindingRepository->findOpenByTenant($tenant));
    }

    /**
     * Mean acknowledgement coverage across all published, generated
     * documents in the tenant. Returns 100.0 when there are no published
     * generated policies (audit-defang for sandbox tenants — same
     * convention as {@see PolicyAcknowledgementService::coverageFor}).
     */
    private function averageAckCoverage(Tenant $tenant): float
    {
        $documents = $this->entityManager->getRepository(Document::class)
            ->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->andWhere('d.generatedFromTemplate IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['published', 'approved'])
            ->getQuery()
            ->getResult();

        if ($documents === []) {
            return 100.0;
        }

        $sum = 0.0;
        $counted = 0;
        foreach ($documents as $document) {
            if (!$document instanceof Document) {
                continue;
            }
            $coverage = $this->acknowledgementService->coverageFor($document);
            $sum += (float) $coverage['percent'];
            $counted++;
        }

        if ($counted === 0) {
            return 100.0;
        }

        return round($sum / $counted, 1);
    }

    private function countDpoVetoesThisQuarter(Tenant $tenant): int
    {
        $now = new DateTimeImmutable();
        $quarterStart = $this->quarterStart($now);

        $result = $this->entityManager->getRepository(DocumentSection::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.tenant = :tenant')
            ->andWhere('s.status = :status')
            ->andWhere('s.approvalRole = :role')
            ->andWhere('s.rejectedAt >= :since')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', DocumentSection::STATUS_REJECTED)
            ->setParameter('role', DocumentSection::APPROVAL_ROLE_DPO)
            ->setParameter('since', $quarterStart)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    private function quarterStart(DateTimeImmutable $now): DateTimeImmutable
    {
        $month = (int) $now->format('n');
        $quarterFirstMonth = (int) (floor(($month - 1) / 3) * 3) + 1;
        $year = (int) $now->format('Y');

        return (new DateTimeImmutable())
            ->setDate($year, $quarterFirstMonth, 1)
            ->setTime(0, 0, 0);
    }

    /**
     * Format a float coverage as a single-decimal percent string (Twig
     * helper-equivalent). Exposed for tests.
     */
    public function formatCoverage(float $percent): string
    {
        return number_format($percent, 1, '.', '') . '%';
    }

    /**
     * Convenience: return the DateInterval representing how long ago the
     * quarter started — kept here so the template can render a
     * "since X days" subtitle without duplicating the math.
     */
    public function quarterAge(DateTimeImmutable $now = new DateTimeImmutable()): DateInterval
    {
        return $this->quarterStart($now)->diff($now);
    }
}
