<?php

namespace App\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use App\Entity\BusinessProcess;
use App\Form\BusinessProcessType;
use App\Repository\BusinessProcessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class BusinessProcessController extends AbstractController
{
    public function __construct(
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security
    ) {}
    #[Route('/bcm/business-process/', name: 'app_business_process_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get view filter parameter
        $view = $request->query->get('view', 'own'); // Default: inherited

        // Get business processes based on view filter
        if ($tenant) {
            $processes = match ($view) {
                'own' => $this->businessProcessRepository->findByTenant($tenant),
                'subsidiaries' => $this->businessProcessRepository->findByTenantIncludingSubsidiaries($tenant),
                default => $this->businessProcessRepository->findByTenantIncludingParent($tenant),
            };
            $inheritanceInfo = [
                'hasParent' => $tenant->getParent() !== null,
                'hasSubsidiaries' => $tenant->getSubsidiaries()->count() > 0,
                'currentView' => $view
            ];
        } else {
            $processes = $this->businessProcessRepository->findAll();
            $inheritanceInfo = [
                'hasParent' => false,
                'hasSubsidiaries' => false,
                'currentView' => 'own'
            ];
        }

        // Calculate statistics
        $stats = [
            'total' => count($processes),
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'avg_rto' => 0,
            'avg_rpo' => 0,
            'total_financial_impact' => 0,
            'processes_with_risks' => 0,
        ];

        $totalRto = 0;
        $totalRpo = 0;

        foreach ($processes as $process) {
            // Count by criticality
            $criticality = $process->getCriticality();
            if (isset($stats[$criticality])) {
                $stats[$criticality]++;
            }

            // Calculate averages
            $totalRto += $process->getRto();
            $totalRpo += $process->getRpo();

            // Financial impact
            if ($process->getFinancialImpactPerDay()) {
                $stats['total_financial_impact'] += (float) $process->getFinancialImpactPerDay();
            }

            // Processes with risks
            if ($process->getActiveRiskCount() > 0) {
                $stats['processes_with_risks']++;
            }
        }

        if ($stats['total'] > 0) {
            $stats['avg_rto'] = round($totalRto / $stats['total'], 1);
            $stats['avg_rpo'] = round($totalRpo / $stats['total'], 1);
        }

        // Calculate detailed statistics based on origin
        if ($tenant) {
            $detailedStats = $this->calculateDetailedStats($processes, $tenant);
        } else {
            $detailedStats = ['own' => count($processes), 'inherited' => 0, 'subsidiaries' => 0, 'total' => count($processes)];
        }

        return $this->render('business_process/index.html.twig', [
            'business_processes' => $processes,
            'stats' => $stats,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
        ]);
    }
    #[Route('/bcm/business-process/new', name: 'app_business_process_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $businessProcess = new BusinessProcess();

        // Set tenant from current user
        $user = $this->security->getUser();
        if ($user instanceof UserInterface && $user->getTenant()) {
            $businessProcess->setTenant($user->getTenant());
        }

        $form = $this->createForm(BusinessProcessType::class, $businessProcess);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($businessProcess);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('business_process.success.created'));
            return $this->redirectToRoute('app_business_process_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('business_process/new.html.twig', [
            'business_process' => $businessProcess,
            'form' => $form,
        ]);
    }
    #[Route('/bcm/business-process/api/stats', name: 'app_business_process_stats_api', methods: ['GET'])]
    public function statsApi(BusinessProcessRepository $businessProcessRepository): Response
    {
        $processes = $businessProcessRepository->findAll();

        $stats = [
            'total' => count($processes),
            'by_criticality' => [
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0,
            ],
            'avg_rto' => 0,
            'avg_rpo' => 0,
            'processes_with_high_risks' => 0,
        ];

        $totalRto = 0;
        $totalRpo = 0;

        foreach ($processes as $process) {
            $criticality = $process->getCriticality();
            if (isset($stats['by_criticality'][$criticality])) {
                $stats['by_criticality'][$criticality]++;
            }

            $totalRto += $process->getRto();
            $totalRpo += $process->getRpo();

            if ($process->hasUnmitigatedHighRisks()) {
                $stats['processes_with_high_risks']++;
            }
        }

        if ($stats['total'] > 0) {
            $stats['avg_rto'] = round($totalRto / $stats['total'], 1);
            $stats['avg_rpo'] = round($totalRpo / $stats['total'], 1);
        }

        return $this->json($stats);
    }
    #[Route('/bcm/business-process/{id}', name: 'app_business_process_show', methods: ['GET'])]
    public function show(BusinessProcess $businessProcess): Response
    {
        // Calculate additional metrics for display
        $metrics = [
            'business_impact_score' => $businessProcess->getBusinessImpactScore(),
            'suggested_availability' => $businessProcess->getSuggestedAvailabilityValue(),
            'process_risk_level' => $businessProcess->getProcessRiskLevel(),
            'criticality_aligned' => $businessProcess->isCriticalityAligned(),
            'active_risks' => $businessProcess->getActiveRiskCount(),
            'unmitigated_high_risks' => $businessProcess->hasUnmitigatedHighRisks(),
            'suggested_rto' => $businessProcess->getSuggestedRTO(),
        ];

        return $this->render('business_process/show.html.twig', [
            'business_process' => $businessProcess,
            'metrics' => $metrics,
        ]);
    }
    #[Route('/bcm/business-process/{id}/edit', name: 'app_business_process_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, BusinessProcess $businessProcess, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(BusinessProcessType::class, $businessProcess);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('business_process.success.updated'));
            return $this->redirectToRoute('app_business_process_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('business_process/edit.html.twig', [
            'business_process' => $businessProcess,
            'form' => $form,
        ]);
    }
    #[Route('/bcm/business-process/{id}', name: 'app_business_process_delete', methods: ['POST'])]
    public function delete(Request $request, BusinessProcess $businessProcess, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if ($this->isCsrfTokenValid('delete'.$businessProcess->getId(), $request->request->get('_token'))) {
            $entityManager->remove($businessProcess);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('business_process.success.deleted'));
        }

        return $this->redirectToRoute('app_business_process_index', [], Response::HTTP_SEE_OTHER);
    }
    #[Route('/bcm/business-process/{id}/bia', name: 'app_business_process_bia', methods: ['GET'])]
    public function bia(BusinessProcess $businessProcess): Response
    {
        // Business Impact Analysis view
        $biaData = [
            'rto' => $businessProcess->getRto(),
            'rpo' => $businessProcess->getRpo(),
            'mtpd' => $businessProcess->getMtpd(),
            'financial_impact_hour' => $businessProcess->getFinancialImpactPerHour(),
            'financial_impact_day' => $businessProcess->getFinancialImpactPerDay(),
            'reputational_impact' => $businessProcess->getReputationalImpact(),
            'regulatory_impact' => $businessProcess->getRegulatoryImpact(),
            'operational_impact' => $businessProcess->getOperationalImpact(),
            'business_impact_score' => $businessProcess->getBusinessImpactScore(),
            'suggested_availability' => $businessProcess->getSuggestedAvailabilityValue(),
        ];

        return $this->render('business_process/bia.html.twig', [
            'business_process' => $businessProcess,
            'bia_data' => $biaData,
        ]);
    }
    /**
     * Calculate detailed statistics showing breakdown by origin
     */
    private function calculateDetailedStats(array $items, $currentTenant): array
    {
        $ownCount = 0;
        $inheritedCount = 0;
        $subsidiariesCount = 0;

        // Get ancestors and subsidiaries for comparison
        $ancestors = $currentTenant->getAllAncestors();
        $ancestorIds = array_map(fn($t) => $t->getId(), $ancestors);

        $subsidiaries = $currentTenant->getAllSubsidiaries();
        $subsidiaryIds = array_map(fn($t) => $t->getId(), $subsidiaries);

        foreach ($items as $item) {
            $itemTenant = $item->getTenant();
            if (!$itemTenant) {
                continue;
            }

            $itemTenantId = $itemTenant->getId();
            $currentTenantId = $currentTenant->getId();

            if ($itemTenantId === $currentTenantId) {
                $ownCount++;
            } elseif (in_array($itemTenantId, $ancestorIds)) {
                $inheritedCount++;
            } elseif (in_array($itemTenantId, $subsidiaryIds)) {
                $subsidiariesCount++;
            }
        }

        return [
            'own' => $ownCount,
            'inherited' => $inheritedCount,
            'subsidiaries' => $subsidiariesCount,
            'total' => $ownCount + $inheritedCount + $subsidiariesCount
        ];
    }
}
