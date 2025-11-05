<?php

namespace App\Controller;

use App\Entity\BusinessProcess;
use App\Form\BusinessProcessType;
use App\Repository\BusinessProcessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/bcm/business-process')]
class BusinessProcessController extends AbstractController
{
    #[Route('/', name: 'app_business_process_index', methods: ['GET'])]
    public function index(BusinessProcessRepository $businessProcessRepository): Response
    {
        $processes = $businessProcessRepository->findAll();

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

        return $this->render('business_process/index.html.twig', [
            'business_processes' => $processes,
            'stats' => $stats,
        ]);
    }

    #[Route('/new', name: 'app_business_process_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, BusinessProcessRepository $businessProcessRepository): Response
    {
        $businessProcess = new BusinessProcess();
        $form = $this->createForm(BusinessProcessType::class, $businessProcess);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($businessProcess);
            $entityManager->flush();

            // Check if this is a Turbo Stream request
            if ($request->getPreferredFormat() === 'turbo_stream' ||
                $request->headers->get('Accept') === 'text/vnd.turbo-stream.html') {

                $totalCount = $businessProcessRepository->count([]);

                return $this->render('business_process/create.turbo_stream.html.twig', [
                    'business_process' => $businessProcess,
                    'total_count' => $totalCount,
                ]);
            }

            $this->addFlash('success', 'Geschäftsprozess erfolgreich erstellt.');
            return $this->redirectToRoute('app_business_process_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('business_process/new.html.twig', [
            'business_process' => $businessProcess,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_business_process_show', methods: ['GET'])]
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

    #[Route('/{id}/edit', name: 'app_business_process_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, BusinessProcess $businessProcess, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(BusinessProcessType::class, $businessProcess);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            // Check if this is a Turbo Stream request
            if ($request->getPreferredFormat() === 'turbo_stream' ||
                $request->headers->get('Accept') === 'text/vnd.turbo-stream.html') {

                return $this->render('business_process/update.turbo_stream.html.twig', [
                    'business_process' => $businessProcess,
                ]);
            }

            $this->addFlash('success', 'Geschäftsprozess erfolgreich aktualisiert.');
            return $this->redirectToRoute('app_business_process_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('business_process/edit.html.twig', [
            'business_process' => $businessProcess,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_business_process_delete', methods: ['POST'])]
    public function delete(Request $request, BusinessProcess $businessProcess, EntityManagerInterface $entityManager, BusinessProcessRepository $businessProcessRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$businessProcess->getId(), $request->request->get('_token'))) {
            $processId = $businessProcess->getId();
            $wasCritical = $businessProcess->getCriticality() === 'critical';
            $wasHigh = $businessProcess->getCriticality() === 'high';

            $entityManager->remove($businessProcess);
            $entityManager->flush();

            // Check if this is a Turbo Stream request
            if ($request->getPreferredFormat() === 'turbo_stream' ||
                $request->headers->get('Accept') === 'text/vnd.turbo-stream.html') {

                $totalCount = $businessProcessRepository->count([]);
                $criticalCount = $businessProcessRepository->count(['criticality' => 'critical']);
                $highCount = $businessProcessRepository->count(['criticality' => 'high']);

                return $this->render('business_process/delete.turbo_stream.html.twig', [
                    'business_process_id' => $processId,
                    'total_count' => $totalCount,
                    'critical_count' => $criticalCount,
                    'high_count' => $highCount,
                    'was_critical' => $wasCritical,
                    'was_high' => $wasHigh,
                ]);
            }

            $this->addFlash('success', 'Geschäftsprozess erfolgreich gelöscht.');
        }

        return $this->redirectToRoute('app_business_process_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/bia', name: 'app_business_process_bia', methods: ['GET'])]
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

    #[Route('/api/stats', name: 'app_business_process_stats_api', methods: ['GET'])]
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
}
