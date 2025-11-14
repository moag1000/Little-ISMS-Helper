<?php

namespace App\Controller;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceMappingRepository;
use App\Repository\MappingGapItemRepository;
use App\Service\MappingQualityAnalysisService;
use App\Service\AutomatedGapAnalysisService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/compliance/mapping-quality')]
#[IsGranted('ROLE_USER')]
class MappingQualityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ComplianceMappingRepository $mappingRepository,
        private MappingGapItemRepository $gapItemRepository,
        private MappingQualityAnalysisService $qualityAnalysisService,
        private AutomatedGapAnalysisService $gapAnalysisService
    ) {}

    /**
     * Dashboard showing mapping quality overview
     */
    #[Route('/', name: 'app_mapping_quality_dashboard')]
    public function dashboard(): Response
    {
        try {
            // Check if any mappings exist
            $totalMappings = $this->mappingRepository->count([]);
            if ($totalMappings === 0) {
                $this->addFlash('warning', 'Keine Mappings gefunden. Bitte erstellen Sie zuerst Compliance-Mappings.');
                return $this->redirectToRoute('app_compliance_index');
            }

            $qualityStats = $this->mappingRepository->getQualityStatistics();
            $qualityDistribution = $this->mappingRepository->getQualityDistribution();
            $similarityDistribution = $this->mappingRepository->getSimilarityDistribution();
            $gapStats = $this->gapItemRepository->getGapStatisticsByPriority();
            $frameworkComparison = $this->mappingRepository->getFrameworkQualityComparison();

            // Check if analysis has been run
            if ($qualityStats['analyzed_mappings'] === 0) {
                $this->addFlash('info', 'Noch keine Analyse durchgeführt. Führen Sie zuerst "php bin/console app:analyze-mapping-quality" aus.');
            }

            return $this->render('compliance/mapping_quality/dashboard.html.twig', [
                'quality_stats' => $qualityStats,
                'quality_distribution' => $qualityDistribution,
                'similarity_distribution' => $similarityDistribution,
                'gap_stats' => $gapStats ?? [],
                'framework_comparison' => $frameworkComparison ?? [],
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler beim Laden des Dashboards: ' . $e->getMessage());
            return $this->redirectToRoute('app_compliance_index');
        }
    }

    /**
     * List mappings requiring review
     */
    #[Route('/review-queue', name: 'app_mapping_quality_review_queue')]
    public function reviewQueue(): Response
    {
        try {
            $mappingsRequiringReview = $this->mappingRepository->findMappingsRequiringReview();
            $lowConfidenceMappings = $this->mappingRepository->findLowConfidenceMappings(70);
            $discrepancies = $this->mappingRepository->findMappingsWithDiscrepancies(20);

            return $this->render('compliance/mapping_quality/review_queue.html.twig', [
                'mappings_requiring_review' => $mappingsRequiringReview ?? [],
                'low_confidence_mappings' => $lowConfidenceMappings ?? [],
                'discrepancies' => $discrepancies ?? [],
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler beim Laden der Review Queue: ' . $e->getMessage());
            return $this->redirectToRoute('app_mapping_quality_dashboard');
        }
    }

    /**
     * Review a specific mapping
     */
    #[Route('/review/{id}', name: 'app_mapping_quality_review', requirements: ['id' => '\d+'])]
    public function review(int $id): Response
    {
        $mapping = $this->mappingRepository->find($id);

        if (!$mapping) {
            throw $this->createNotFoundException('Mapping not found');
        }

        $gapItems = $this->gapItemRepository->findByMapping($mapping);

        // Calculate gap summary
        $gapSummary = $this->gapAnalysisService->getGapSummary($gapItems);

        return $this->render('compliance/mapping_quality/review.html.twig', [
            'mapping' => $mapping,
            'gap_items' => $gapItems,
            'gap_summary' => $gapSummary,
        ]);
    }

    /**
     * Update mapping review status and percentage
     */
    #[Route('/review/{id}/update', name: 'app_mapping_quality_review_update', methods: ['POST'])]
    public function updateReview(int $id, Request $request): JsonResponse
    {
        try {
            $mapping = $this->mappingRepository->find($id);

            if (!$mapping) {
                return $this->json(['success' => false, 'error' => 'Mapping not found'], 404);
            }

            $data = json_decode($request->getContent(), true);

            // Validate JSON parsing
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid JSON: ' . json_last_error_msg()
                ], 400);
            }

            // Validate review status
            if (isset($data['review_status'])) {
                $validStatuses = ['unreviewed', 'in_review', 'approved', 'rejected'];
                if (!in_array($data['review_status'], $validStatuses)) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Invalid review status. Must be one of: ' . implode(', ', $validStatuses)
                    ], 400);
                }
                $mapping->setReviewStatus($data['review_status']);
            }

            // Validate and update manual percentage override
            if (isset($data['manual_percentage']) && $data['manual_percentage'] !== null && $data['manual_percentage'] !== '') {
                $manualPercentage = (int) $data['manual_percentage'];
                if ($manualPercentage < 0 || $manualPercentage > 150) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Manual percentage must be between 0 and 150'
                    ], 400);
                }
                $mapping->setManualPercentage($manualPercentage);
                $mapping->setMappingPercentage($manualPercentage); // Update actual percentage
            }

            // Update review notes
            if (isset($data['review_notes'])) {
                $mapping->setReviewNotes($data['review_notes']);
            }

            // Mark as reviewed
            $user = $this->getUser();
            if ($user) {
                $mapping->setReviewedBy($user->getUserIdentifier());
            }
            $mapping->setReviewedAt(new \DateTimeImmutable());
            $mapping->setUpdatedAt(new \DateTimeImmutable());

            // If approved, mark as no longer requiring review
            if (isset($data['review_status']) && $data['review_status'] === 'approved') {
                $mapping->setRequiresReview(false);
            }

            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'mapping' => [
                    'id' => $mapping->getId(),
                    'review_status' => $mapping->getReviewStatus(),
                    'final_percentage' => $mapping->getFinalPercentage(),
                    'reviewed_by' => $mapping->getReviewedBy(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Internal error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Re-analyze a specific mapping
     */
    #[Route('/analyze/{id}', name: 'app_mapping_quality_analyze', methods: ['POST'])]
    public function analyze(int $id): JsonResponse
    {
        $mapping = $this->mappingRepository->find($id);

        if (!$mapping) {
            return $this->json(['error' => 'Mapping not found'], 404);
        }

        try {
            // Run quality analysis
            $analysisResults = $this->qualityAnalysisService->analyzeMappingQuality($mapping);

            // Apply results
            $mapping->setCalculatedPercentage($analysisResults['calculated_percentage']);
            $mapping->setTextualSimilarity($analysisResults['textual_similarity']);
            $mapping->setKeywordOverlap($analysisResults['keyword_overlap']);
            $mapping->setStructuralSimilarity($analysisResults['structural_similarity']);
            $mapping->setAnalysisConfidence($analysisResults['analysis_confidence']);
            $mapping->setQualityScore($analysisResults['quality_score']);
            $mapping->setAnalysisAlgorithmVersion($analysisResults['algorithm_version']);
            $mapping->setRequiresReview($analysisResults['requires_review']);

            // Remove old gap items
            foreach ($mapping->getGapItems() as $oldGap) {
                $this->entityManager->remove($oldGap);
            }
            $this->entityManager->flush();

            // Generate new gap items
            $gapItems = $this->gapAnalysisService->analyzeGaps($mapping, $analysisResults);

            foreach ($gapItems as $gapItem) {
                $mapping->addGapItem($gapItem);
                $this->entityManager->persist($gapItem);
            }

            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'analysis' => [
                    'calculated_percentage' => $analysisResults['calculated_percentage'],
                    'confidence' => $analysisResults['analysis_confidence'],
                    'quality_score' => $analysisResults['quality_score'],
                    'requires_review' => $analysisResults['requires_review'],
                    'gap_count' => count($gapItems),
                ],
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all gaps
     */
    #[Route('/gaps', name: 'app_mapping_quality_gaps')]
    public function gaps(): Response
    {
        try {
            $highPriorityGaps = $this->gapItemRepository->findHighPriorityGaps();
            $lowConfidenceGaps = $this->gapItemRepository->findLowConfidenceGaps(60);
            $gapStatsByType = $this->gapItemRepository->getGapStatisticsByType();
            $gapStatsByPriority = $this->gapItemRepository->getGapStatisticsByPriority();
            $remediationEffort = $this->gapItemRepository->calculateTotalRemediationEffort();

            return $this->render('compliance/mapping_quality/gaps.html.twig', [
                'high_priority_gaps' => $highPriorityGaps ?? [],
                'low_confidence_gaps' => $lowConfidenceGaps ?? [],
                'gap_stats_by_type' => $gapStatsByType ?? [],
                'gap_stats_by_priority' => $gapStatsByPriority ?? [],
                'remediation_effort' => $remediationEffort ?? null,
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler beim Laden der Gap-Übersicht: ' . $e->getMessage());
            return $this->redirectToRoute('app_mapping_quality_dashboard');
        }
    }

    /**
     * Update gap item status
     */
    #[Route('/gap/{id}/update', name: 'app_mapping_quality_gap_update', methods: ['POST'])]
    public function updateGap(int $id, Request $request): JsonResponse
    {
        try {
            $gap = $this->gapItemRepository->find($id);

            if (!$gap) {
                return $this->json(['success' => false, 'error' => 'Gap item not found'], 404);
            }

            $data = json_decode($request->getContent(), true);

            // Validate JSON parsing
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid JSON: ' . json_last_error_msg()
                ], 400);
            }

            // Validate and update status
            if (isset($data['status'])) {
                $validStatuses = ['identified', 'planned', 'in_progress', 'resolved', 'wont_fix'];
                if (!in_array($data['status'], $validStatuses)) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Invalid status. Must be one of: ' . implode(', ', $validStatuses)
                    ], 400);
                }
                $gap->setStatus($data['status']);
            }

            // Validate and update priority
            if (isset($data['priority'])) {
                $validPriorities = ['critical', 'high', 'medium', 'low'];
                if (!in_array($data['priority'], $validPriorities)) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Invalid priority. Must be one of: ' . implode(', ', $validPriorities)
                    ], 400);
                }
                $gap->setPriority($data['priority']);
            }

            // Validate and update estimated effort
            if (isset($data['estimated_effort'])) {
                $effort = (int) $data['estimated_effort'];
                if ($effort < 0 || $effort > 1000) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Estimated effort must be between 0 and 1000 hours'
                    ], 400);
                }
                $gap->setEstimatedEffort($effort);
            }

            $gap->setUpdatedAt(new \DateTimeImmutable());

            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'gap' => [
                    'id' => $gap->getId(),
                    'status' => $gap->getStatus(),
                    'priority' => $gap->getPriority(),
                    'estimated_effort' => $gap->getEstimatedEffort(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Internal error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export quality report
     */
    #[Route('/export', name: 'app_mapping_quality_export')]
    public function export(): Response
    {
        $qualityStats = $this->mappingRepository->getQualityStatistics();
        $mappingsRequiringReview = $this->mappingRepository->findMappingsRequiringReview();
        $highPriorityGaps = $this->gapItemRepository->findHighPriorityGaps();

        // For now, return JSON (can be extended to PDF/Excel later)
        return $this->json([
            'quality_statistics' => $qualityStats,
            'mappings_requiring_review_count' => count($mappingsRequiringReview),
            'high_priority_gaps_count' => count($highPriorityGaps),
            'export_date' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
