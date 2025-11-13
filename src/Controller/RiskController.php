<?php

namespace App\Controller;

use App\Entity\Risk;
use App\Form\RiskType;
use App\Repository\AuditLogRepository;
use App\Repository\RiskRepository;
use App\Service\RiskMatrixService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/risk')]
class RiskController extends AbstractController
{
    public function __construct(
        private RiskRepository $riskRepository,
        private AuditLogRepository $auditLogRepository,
        private EntityManagerInterface $entityManager,
        private RiskMatrixService $riskMatrixService,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_risk_index')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $level = $request->query->get('level'); // critical, high, medium, low
        $status = $request->query->get('status');
        $treatment = $request->query->get('treatment');
        $owner = $request->query->get('owner');

        // Get all risks
        $risks = $this->riskRepository->findAll();

        // Apply filters
        if ($level) {
            $risks = array_filter($risks, function($risk) use ($level) {
                $score = $risk->getRiskScore();
                return match($level) {
                    'critical' => $score >= 15,
                    'high' => $score >= 8 && $score < 15,
                    'medium' => $score >= 4 && $score < 8,
                    'low' => $score < 4,
                    default => true
                };
            });
        }

        if ($status) {
            $risks = array_filter($risks, fn($risk) => $risk->getStatus() === $status);
        }

        if ($treatment) {
            $risks = array_filter($risks, fn($risk) => $risk->getTreatmentStrategy() === $treatment);
        }

        if ($owner) {
            $risks = array_filter($risks, fn($risk) =>
                $risk->getRiskOwner() && stripos($risk->getRiskOwner()->getFullName(), $owner) !== false
            );
        }

        // Re-index array after filtering
        $risks = array_values($risks);

        $highRisks = $this->riskRepository->findHighRisks();
        $treatmentStats = $this->riskRepository->countByTreatmentStrategy();

        return $this->render('risk/index_modern.html.twig', [
            'risks' => $risks,
            'highRisks' => $highRisks,
            'treatmentStats' => $treatmentStats,
        ]);
    }

    #[Route('/export', name: 'app_risk_export')]
    #[IsGranted('ROLE_USER')]
    public function export(Request $request): Response
    {
        // Get filter parameters (same as index)
        $level = $request->query->get('level');
        $status = $request->query->get('status');
        $treatment = $request->query->get('treatment');
        $owner = $request->query->get('owner');

        // Get all risks
        $risks = $this->riskRepository->findAll();

        // Apply filters (same logic as index)
        if ($level) {
            $risks = array_filter($risks, function($risk) use ($level) {
                $score = $risk->getRiskScore();
                return match($level) {
                    'critical' => $score >= 15,
                    'high' => $score >= 8 && $score < 15,
                    'medium' => $score >= 4 && $score < 8,
                    'low' => $score < 4,
                    default => true
                };
            });
        }

        if ($status) {
            $risks = array_filter($risks, fn($risk) => $risk->getStatus() === $status);
        }

        if ($treatment) {
            $risks = array_filter($risks, fn($risk) => $risk->getTreatmentStrategy() === $treatment);
        }

        if ($owner) {
            $risks = array_filter($risks, fn($risk) =>
                $risk->getRiskOwner() && stripos($risk->getRiskOwner()->getFullName(), $owner) !== false
            );
        }

        // Re-index array after filtering
        $risks = array_values($risks);

        // Create CSV content
        $csv = [];

        // CSV Header
        $csv[] = [
            'ID',
            'Titel',
            'Beschreibung',
            'Bedrohung',
            'Schwachstelle',
            'Asset',
            'Wahrscheinlichkeit',
            'Auswirkung',
            'Risiko-Score',
            'Risikolevel',
            'Rest-Wahrscheinlichkeit',
            'Rest-Auswirkung',
            'Rest-Risiko-Score',
            'Rest-Risikolevel',
            'Behandlungsstrategie',
            'Status',
            'Risikoinhaber',
            'Erstellt am',
            'Überprüfungsdatum',
        ];

        // CSV Data
        foreach ($risks as $risk) {
            $riskScore = $risk->getRiskScore();
            $residualScore = $risk->getResidualRiskScore();

            // Determine risk levels
            $riskLevel = match(true) {
                $riskScore >= 15 => 'Kritisch',
                $riskScore >= 8 => 'Hoch',
                $riskScore >= 4 => 'Mittel',
                default => 'Niedrig'
            };

            $residualRiskLevel = match(true) {
                $residualScore >= 15 => 'Kritisch',
                $residualScore >= 8 => 'Hoch',
                $residualScore >= 4 => 'Mittel',
                default => 'Niedrig'
            };

            // Translate treatment strategy
            $treatmentMap = [
                'accept' => 'Akzeptieren',
                'mitigate' => 'Mindern',
                'transfer' => 'Übertragen',
                'avoid' => 'Vermeiden',
            ];

            // Translate status
            $statusMap = [
                'identified' => 'Identifiziert',
                'assessed' => 'Bewertet',
                'treated' => 'Behandelt',
                'monitored' => 'Überwacht',
                'closed' => 'Geschlossen',
                'accepted' => 'Akzeptiert',
            ];

            $csv[] = [
                $risk->getId(),
                $risk->getTitle(),
                $risk->getDescription(),
                $risk->getThreat() ?? '-',
                $risk->getVulnerability() ?? '-',
                $risk->getAsset() ? $risk->getAsset()->getName() : '-',
                $risk->getProbability(),
                $risk->getImpact(),
                $riskScore,
                $riskLevel,
                $risk->getResidualProbability(),
                $risk->getResidualImpact(),
                $residualScore,
                $residualRiskLevel,
                $treatmentMap[$risk->getTreatmentStrategy()] ?? $risk->getTreatmentStrategy(),
                $statusMap[$risk->getStatus()] ?? $risk->getStatus(),
                $risk->getRiskOwner() ? $risk->getRiskOwner()->getFullName() : '-',
                $risk->getCreatedAt() ? $risk->getCreatedAt()->format('Y-m-d H:i') : '-',
                $risk->getReviewDate() ? $risk->getReviewDate()->format('Y-m-d') : '-',
            ];
        }

        // Generate CSV file
        $filename = sprintf(
            'risk_export_%s.csv',
            date('Y-m-d_His')
        );

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        // Add BOM for Excel UTF-8 support
        $csvContent = "\xEF\xBB\xBF";

        // Create CSV content
        $handle = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($handle, $row, ';'); // Use semicolon as delimiter for Excel compatibility
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
    }

    #[Route('/new', name: 'app_risk_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $risk = new Risk();
        $form = $this->createForm(RiskType::class, $risk);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($risk);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk.success.created'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        return $this->render('risk/new.html.twig', [
            'risk' => $risk,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_risk_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(Risk $risk): Response
    {
        // Get audit log history for this risk (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('Risk', $risk->getId());
        $recentAuditLogs = array_slice($auditLogs, 0, 10);

        return $this->render('risk/show.html.twig', [
            'risk' => $risk,
            'auditLogs' => $recentAuditLogs,
            'totalAuditLogs' => count($auditLogs),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_risk_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Risk $risk): Response
    {
        $form = $this->createForm(RiskType::class, $risk);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk.success.updated'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        return $this->render('risk/edit.html.twig', [
            'risk' => $risk,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_risk_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Risk $risk): Response
    {
        if ($this->isCsrfTokenValid('delete'.$risk->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($risk);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk.success.deleted'));
        }

        return $this->redirectToRoute('app_risk_index');
    }

    #[Route('/matrix', name: 'app_risk_matrix')]
    public function matrix(): Response
    {
        $risks = $this->riskRepository->findAll();
        $matrixData = $this->riskMatrixService->generateMatrix();
        $statistics = $this->riskMatrixService->getRiskStatistics();
        $risksByLevel = $this->riskMatrixService->getRisksByLevel();

        return $this->render('risk/matrix.html.twig', [
            'risks' => $risks,
            'matrixData' => $matrixData,
            'statistics' => $statistics,
            'risksByLevel' => $risksByLevel,
        ]);
    }
}
